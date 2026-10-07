// SendentChangeFeedClient.cs
//
// Reference controller for the sendent-sync change feed: decides WHEN the
// Connector checks for changes and HOW it reads them. Wire contract v1 —
// docs/connector-change-feed-contract.md is authoritative. Single file, BCL
// only, no packages. Needs .NET 9 or later (net10.0 LTS recommended) for the
// websocket's ping/pong keep-alive.
//
// The pattern is the Nextcloud desktop client's:
//   - a notify_push hint (the bare text frame "sendent_sync") means "check now"
//   - a check pages GET /notify/changes from our own cursor
//   - on (re)connect: check; without a live websocket: check every poll_interval
//   - a slow full reconcile regardless of transport
//
// Implemented here:
//   - config negotiation (GET /notify/config at startup, after every dropped
//     socket, and every 5 min while polling, so the controller switches
//     between notify_push and polling by itself)
//   - websocket auth (username frame, app-password frame, "authenticated"),
//     hint filtering and ping/pong keep-alive
//   - coalesced checks: at most one runs; hints during it cause exactly one more
//   - contract rule 1 paging: overlap on the first request of a check only,
//     overlap re-reads skipped by sequence (q), cursor persisted after each
//     page, per instance
//   - polling while the websocket is not live, reconcile timer
//
// Left to the Connector (virtual hooks): HandleChangedCollectionAsync (the
// CalDAV/CardDAV sync-collection with the token YOU stored), cursor storage,
// the reconcile itself, and logging.

using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;

namespace Sendent.Connector.ChangeFeed
{
    public class SendentChangeFeedClient : IDisposable
    {
        // ── Wire types (contract v1 field names) ────────────────────────────

        /// <summary>GET /notify/config.</summary>
        public sealed record FeedConfig
        {
            [JsonPropertyName("transport")] public string Transport { get; init; } = "polling"; // "notify_push" | "polling"
            [JsonPropertyName("ws_url")] public string? WsUrl { get; init; }
            [JsonPropertyName("message_name")] public string MessageName { get; init; } = "sendent_sync";
            [JsonPropertyName("poll_interval")] public int PollInterval { get; init; } = 30;
            [JsonPropertyName("reread_overlap")] public int RereadOverlap { get; init; } = 100;
            [JsonPropertyName("instance")] public string Instance { get; init; } = ""; // keys the stored cursor
        }

        /// <summary>One changed collection. p = owner principal — the user.</summary>
        public sealed record ChangeRef
        {
            [JsonPropertyName("p")] public string PrincipalUri { get; init; } = "";
            [JsonPropertyName("t")] public string CollectionType { get; init; } = ""; // "caldav" | "carddav"
            [JsonPropertyName("u")] public string CollectionUri { get; init; } = "";
            [JsonPropertyName("c")] public bool CollectionChanged { get; init; }      // created/deleted/share changed
            [JsonPropertyName("q")] public long Seq { get; init; }                    // this version's sequence number

            /// <summary>
            /// The Nextcloud user id — the feed only emits "principals/users/&lt;uid&gt;".
            /// URL-encode it (and CollectionUri) when building the DAV URL.
            /// </summary>
            public string Uid => PrincipalUri.StartsWith("principals/users/", StringComparison.Ordinal)
                ? PrincipalUri["principals/users/".Length..]
                : PrincipalUri;
        }

        /// <summary>One GET /notify/changes page.</summary>
        public sealed record ChangePage
        {
            [JsonPropertyName("instance")] public string Instance { get; init; } = "";
            [JsonPropertyName("cursor")] public long Cursor { get; init; }
            [JsonPropertyName("has_more")] public bool HasMore { get; init; }
            [JsonPropertyName("refs")] public List<ChangeRef> Refs { get; init; } = new();
        }

        // ── Tuning ──────────────────────────────────────────────────────────

        /// <summary>Refs per /changes request; the server clamps to 1000.</summary>
        protected virtual int PageLimit => 500;

        /// <summary>While polling, how often to re-read /config to notice notify_push coming back.</summary>
        protected virtual TimeSpan ConfigRefreshInterval => TimeSpan.FromMinutes(5);

        /// <summary>Contract rule 5.</summary>
        protected virtual TimeSpan ReconcileInterval => TimeSpan.FromHours(6);

        /// <summary>Connect plus the notify_push login.</summary>
        protected virtual TimeSpan HandshakeTimeout => TimeSpan.FromSeconds(30);

        private static readonly TimeSpan MaxBackoff = TimeSpan.FromMinutes(5);

        // ── State ───────────────────────────────────────────────────────────

        private readonly HttpClient _http;
        private readonly string _botUser;
        private readonly string _appPassword;

        private readonly SemaphoreSlim _checkSignal = new(0);
        private int _checkPending;              // 1 while a check is requested but not started
        private volatile bool _pushConnected;   // a live, authenticated websocket: hints replace polling
        private int _authentications;
        private volatile int _pollIntervalSeconds = 30;
        private volatile int _overlap = 100;

        // Only the check loop touches these once RunAsync has started it.
        private string _instance = "";
        private long _lastCursor;
        // Collection → the highest sequence already handed over, for the rows an
        // overlap read can return again. Without it every check would re-sync
        // every collection changed within the last reread_overlap sequences.
        private readonly Dictionary<(string, string, string), long> _handled = new();

        /// <param name="nextcloudUrl">e.g. https://cloud.example.com/</param>
        /// <param name="botUser">the service account set with occ sendentsynchroniser:cn-setup --bot-user</param>
        /// <param name="appPassword">an app password generated for that account</param>
        /// <param name="handler">optional, e.g. for a proxy; must keep cookies</param>
        public SendentChangeFeedClient(Uri nextcloudUrl, string botUser, string appPassword, HttpMessageHandler? handler = null)
        {
            _botUser = botUser;
            _appPassword = appPassword;

            // Keep cookies: Nextcloud then reuses the session instead of
            // verifying the app password and opening a new session on every
            // request (measured ~20 ms instead of ~150 ms per request).
            _http = new HttpClient(handler ?? new HttpClientHandler { CookieContainer = new CookieContainer() })
            {
                BaseAddress = new Uri(EnsureTrailingSlash(nextcloudUrl), "index.php/apps/sendentsynchroniser/api/1.0/notify/"),
                Timeout = TimeSpan.FromSeconds(30),
            };
            _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue(
                "Basic", Convert.ToBase64String(Encoding.UTF8.GetBytes(botUser + ":" + appPassword)));
            _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        }

        /// <summary>Runs until cancelled. Every failure is logged through OnError and retried.</summary>
        public async Task RunAsync(CancellationToken ct)
        {
            var config = await GetConfigUntilAvailableAsync(ct);
            if (config is null)
            {
                return;
            }
            ApplyConfig(config);
            _instance = config.Instance;
            _lastCursor = await LoadCursorAsync(_instance, ct);

            RequestCheck(); // whatever changed while we were not running
            await Task.WhenAll(
                CheckLoopAsync(ct),
                PushLoopAsync(config, ct),
                PollLoopAsync(ct),
                ReconcileLoopAsync(ct));
        }

        // ── Hooks the Connector implements ──────────────────────────────────

        /// <summary>The cursor stored for this Nextcloud instance; 0 when none (the first check then reads the whole feed).</summary>
        protected virtual Task<long> LoadCursorAsync(string instance, CancellationToken ct) => Task.FromResult(0L);

        /// <summary>Called after each fully processed page. TODO: persist per instance.</summary>
        protected virtual Task SaveCursorAsync(string instance, long cursor, CancellationToken ct) => Task.CompletedTask;

        /// <summary>
        /// TODO: enqueue a sync-collection for (Uid, CollectionType, CollectionUri)
        /// using the sync token YOUR side stored. Must be idempotent. The
        /// cursor moves past this ref once the page is done, so make the queued
        /// work survive a restart if you persist the cursor — otherwise only the
        /// reconcile picks it up again.
        /// </summary>
        protected virtual Task HandleChangedCollectionAsync(ChangeRef changeRef, CancellationToken ct) => Task.CompletedTask;

        /// <summary>Every ReconcileInterval. TODO: sync-collection for every collection you sync, spread out (contract rule 5).</summary>
        protected virtual Task ReconcileAsync(CancellationToken ct) => Task.CompletedTask;

        /// <summary>TODO: log. The controller retries on its own.</summary>
        protected virtual void OnError(string context, Exception exception)
        {
        }

        /// <summary>The websocket became live (hints replace polling) or dropped (polling covers the gap).</summary>
        protected virtual void OnPushConnectionChanged(bool connected)
        {
        }

        /// <summary>A check finished: the cursor it reached and how many distinct collections it handed over.</summary>
        protected virtual void OnCheckCompleted(long cursor, int collections)
        {
        }

        // ── Checks ──────────────────────────────────────────────────────────

        /// <summary>Asks for a check; any number of requests before it starts collapse into one.</summary>
        protected void RequestCheck()
        {
            if (Interlocked.Exchange(ref _checkPending, 1) == 0)
            {
                _checkSignal.Release();
            }
        }

        private async Task CheckLoopAsync(CancellationToken ct)
        {
            while (!ct.IsCancellationRequested)
            {
                try
                {
                    await _checkSignal.WaitAsync(ct);
                }
                catch (OperationCanceledException)
                {
                    return;
                }

                // Hints arriving from here on schedule exactly one more check.
                Interlocked.Exchange(ref _checkPending, 0);
                try
                {
                    await CheckAsync(ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception e)
                {
                    OnError("check", e); // the next hint, reconnect or poll retries
                }
            }
        }

        /// <summary>One check, contract rule 1.</summary>
        private async Task CheckAsync(CancellationToken ct)
        {
            var handedOver = 0;
            var since = Math.Max(0, _lastCursor - _overlap); // the overlap applies to the first request only
            var switchedInstance = false;
            var stalled = 0;

            while (true)
            {
                var page = await GetJsonAsync<ChangePage>(
                    FormattableString.Invariant($"changes?since={since}&limit={PageLimit}"), ct);

                if (page.Instance != _instance)
                {
                    // The Nextcloud behind this address was reinstalled or
                    // replaced: our cursor means nothing against its sequence.
                    if (switchedInstance)
                    {
                        throw new InvalidDataException("the instance id changed twice within one check");
                    }
                    switchedInstance = true;
                    _instance = page.Instance;
                    _lastCursor = await LoadCursorAsync(_instance, ct);
                    since = Math.Max(0, _lastCursor - _overlap);
                    _handled.Clear();
                    continue;
                }

                foreach (var changeRef in page.Refs)
                {
                    var key = (changeRef.PrincipalUri, changeRef.CollectionType, changeRef.CollectionUri);
                    if (_handled.TryGetValue(key, out var handledSeq) && handledSeq >= changeRef.Seq)
                    {
                        continue; // an overlap re-read of a version we already handed over
                    }
                    _handled[key] = changeRef.Seq;
                    await HandleChangedCollectionAsync(changeRef, ct);
                    handedOver++;
                }

                if (page.Cursor > _lastCursor) // monotonic compare only; sequences are not gapless
                {
                    _lastCursor = page.Cursor;
                    await SaveCursorAsync(_instance, _lastCursor, ct);
                }

                if (!page.HasMore)
                {
                    break;
                }

                // The server holds its cursor back while rows are still
                // committing and moves on by itself on the next request.
                stalled = page.Cursor > since ? 0 : stalled + 1;
                if (stalled >= 3)
                {
                    RequestCheck();
                    break;
                }
                since = page.Cursor;
            }

            // A row at or below the next check's start can only come back with a
            // higher sequence, which is a new version anyway.
            var nextStart = _lastCursor - _overlap;
            foreach (var key in _handled.Where(entry => entry.Value <= nextStart).Select(entry => entry.Key).ToList())
            {
                _handled.Remove(key);
            }

            OnCheckCompleted(_lastCursor, handedOver);
        }

        // ── Transports ──────────────────────────────────────────────────────

        private async Task PushLoopAsync(FeedConfig config, CancellationToken ct)
        {
            var backoff = TimeSpan.FromSeconds(1);
            while (!ct.IsCancellationRequested)
            {
                if (config.Transport == "notify_push" && config.WsUrl is not null)
                {
                    var authenticationsBefore = Volatile.Read(ref _authentications);
                    try
                    {
                        await RunSocketAsync(new Uri(config.WsUrl), config.MessageName, ct);
                    }
                    catch (OperationCanceledException) when (ct.IsCancellationRequested)
                    {
                        return;
                    }
                    catch (Exception e)
                    {
                        OnError("websocket", e);
                    }
                    finally
                    {
                        if (_pushConnected)
                        {
                            _pushConnected = false; // polling covers the gap
                            OnPushConnectionChanged(false);
                        }
                    }

                    // A socket that got as far as "authenticated" reconnects
                    // quickly; repeated failures back off.
                    backoff = Volatile.Read(ref _authentications) != authenticationsBefore
                        ? TimeSpan.FromSeconds(1)
                        : TimeSpan.FromTicks(Math.Min(backoff.Ticks * 2, MaxBackoff.Ticks));
                    if (!await SleepAsync(backoff + TimeSpan.FromMilliseconds(Random.Shared.Next(0, 1000)), ct))
                    {
                        return;
                    }
                }
                else if (!await SleepAsync(ConfigRefreshInterval, ct))
                {
                    return;
                }

                // The transport may have changed: notify_push installed or
                // removed, its daemon down or back.
                var refreshed = await GetConfigUntilAvailableAsync(ct);
                if (refreshed is null)
                {
                    return;
                }
                config = refreshed;
                ApplyConfig(config);
            }
        }

        private async Task RunSocketAsync(Uri wsUrl, string messageName, CancellationToken ct)
        {
            using var ws = new ClientWebSocket();
            // Ping/pong keep-alive, as the desktop client does: a hung daemon or
            // a dead path is noticed within ~30 s instead of never. It needs
            // KeepAliveTimeout (.NET 9+). Without it .NET sends unsolicited
            // Pongs, and notify_push closes the connection on any Pong that does
            // not answer its own Ping — every 30 s on an idle socket.
            ws.Options.KeepAliveInterval = TimeSpan.FromSeconds(20);
            ws.Options.KeepAliveTimeout = TimeSpan.FromSeconds(10);

            // A proxy in front of a hung daemon accepts the connection but never
            // answers the upgrade; don't wait for it forever.
            string? reply;
            using (var handshake = CancellationTokenSource.CreateLinkedTokenSource(ct))
            {
                handshake.CancelAfter(HandshakeTimeout);
                await ws.ConnectAsync(wsUrl, handshake.Token);

                // notify_push handshake: two text frames, then "authenticated" (or "err: …").
                await SendTextAsync(ws, _botUser, handshake.Token);
                await SendTextAsync(ws, _appPassword, handshake.Token);
                reply = await ReceiveTextAsync(ws, handshake.Token);
            }
            if (reply != "authenticated")
            {
                throw new InvalidOperationException("notify_push authentication failed: " + (reply ?? "connection closed"));
            }
            Interlocked.Increment(ref _authentications);
            _pushConnected = true;
            OnPushConnectionChanged(true);

            // Anything may have changed while we were not listening.
            RequestCheck();

            while (true)
            {
                var frame = await ReceiveTextAsync(ws, ct);
                if (frame is null)
                {
                    return; // closed by the server
                }
                // The socket also carries the bot's own notify_file/
                // notify_activity/notify_notification frames and the settings
                // page's "sendent_sync_ping" — only the bare hint matters.
                if (frame == messageName)
                {
                    RequestCheck();
                }
            }
        }

        /// <summary>With a live websocket, hints replace polling — as in the desktop client.</summary>
        private async Task PollLoopAsync(CancellationToken ct)
        {
            while (await SleepAsync(TimeSpan.FromSeconds(_pollIntervalSeconds), ct))
            {
                if (!_pushConnected)
                {
                    RequestCheck();
                }
            }
        }

        private async Task ReconcileLoopAsync(CancellationToken ct)
        {
            while (await SleepAsync(ReconcileInterval, ct))
            {
                try
                {
                    await ReconcileAsync(ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch (Exception e)
                {
                    OnError("reconcile", e);
                }
            }
        }

        // ── Plumbing ────────────────────────────────────────────────────────

        private async Task<FeedConfig?> GetConfigUntilAvailableAsync(CancellationToken ct)
        {
            var backoff = TimeSpan.FromSeconds(1);
            while (true)
            {
                try
                {
                    return await GetJsonAsync<FeedConfig>("config", ct);
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return null;
                }
                catch (Exception e)
                {
                    OnError("config", e);
                }
                if (!await SleepAsync(backoff, ct))
                {
                    return null;
                }
                backoff = TimeSpan.FromTicks(Math.Min(backoff.Ticks * 2, MaxBackoff.Ticks));
            }
        }

        private void ApplyConfig(FeedConfig config)
        {
            _pollIntervalSeconds = Math.Max(5, config.PollInterval);
            _overlap = Math.Max(0, config.RereadOverlap);
        }

        private async Task<T> GetJsonAsync<T>(string path, CancellationToken ct)
        {
            using var response = await _http.GetAsync(path, ct);
            // 401: wrong app password. 403: not the bot user set with cn-setup --bot-user.
            response.EnsureSuccessStatusCode();
            await using var body = await response.Content.ReadAsStreamAsync(ct);
            return await JsonSerializer.DeserializeAsync<T>(body, cancellationToken: ct)
                ?? throw new InvalidDataException("empty response from " + path);
        }

        /// <returns>false when cancelled</returns>
        private static async Task<bool> SleepAsync(TimeSpan delay, CancellationToken ct)
        {
            try
            {
                await Task.Delay(delay, ct);
                return true;
            }
            catch (OperationCanceledException)
            {
                return false;
            }
        }

        private static Task SendTextAsync(ClientWebSocket ws, string text, CancellationToken ct)
            => ws.SendAsync(Encoding.UTF8.GetBytes(text), WebSocketMessageType.Text, endOfMessage: true, ct);

        /// <returns>the frame's text, or null when the server closed the socket</returns>
        private static async Task<string?> ReceiveTextAsync(ClientWebSocket ws, CancellationToken ct)
        {
            var buffer = new byte[16 * 1024];
            using var message = new MemoryStream();
            while (true)
            {
                var result = await ws.ReceiveAsync(buffer, ct);
                if (result.MessageType == WebSocketMessageType.Close)
                {
                    return null;
                }
                message.Write(buffer, 0, result.Count);
                if (result.EndOfMessage)
                {
                    // Decode once: a multi-byte character can straddle two chunks.
                    return Encoding.UTF8.GetString(message.GetBuffer(), 0, (int)message.Length);
                }
            }
        }

        private static Uri EnsureTrailingSlash(Uri uri)
            => uri.AbsoluteUri.EndsWith('/') ? uri : new Uri(uri.AbsoluteUri + "/");

        public void Dispose()
        {
            _http.Dispose();
            _checkSignal.Dispose();
            GC.SuppressFinalize(this);
        }
    }
}
