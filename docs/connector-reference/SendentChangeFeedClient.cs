// SendentChangeFeedClient.cs
//
// Minimal receiving skeleton for the sendent-sync change feed (wire contract
// v1 — docs/connector-change-feed-contract.md is authoritative). BCL only
// (net8.0), no csproj, no packages.
//
// This file implements ONLY the websocket receiving end:
//   - notify_push auth handshake (username frame, password frame, "authenticated")
//   - frame filtering + parsing ("sendent_sync {json}", split on the FIRST space)
//   - exact gap detection via the frame's "prev" field
//
// Everything else is deliberately an empty hook for the Connector to fill in:
//   - CatchUpFromLedgerAsync(): page GET /notify/changes with
//     since = max(0, lastCursor - reread_overlap) until has_more=false.
//     Required on startup, after every (re)connect, when a frame's
//     prev > lastCursor, when truncated=true, and on a slow timer (~5 min).
//   - HandleChangedCollectionAsync(): ref.Uid is the user who changed —
//     schedule a CalDAV/CardDAV sync-collection for that collection using the
//     sync token YOUR side last stored (the "s" in the ref is informational).
//   - Cursor persistence, polling fallback, ack, and the 6-hourly reconcile.

using System;
using System.Collections.Generic;
using System.Net.WebSockets;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading;
using System.Threading.Tasks;

namespace Sendent.Connector.ChangeFeed
{
    public class SendentChangeFeedClient
    {
        // ── Wire types (contract v1 field names) ────────────────────────────

        /// <summary>One changed collection. p = owner principal — the user.</summary>
        public sealed record ChangeRef
        {
            [JsonPropertyName("p")] public string PrincipalUri { get; init; } = "";
            [JsonPropertyName("t")] public string CollectionType { get; init; } = ""; // "caldav" | "carddav"
            [JsonPropertyName("u")] public string CollectionUri { get; init; } = "";
            [JsonPropertyName("s")] public long SyncToken { get; init; }              // informational only
            [JsonPropertyName("c")] public bool CollectionChanged { get; init; }      // priority hint

            /// <summary>The Nextcloud user id — the feed only emits "principals/users/&lt;uid&gt;".</summary>
            public string Uid => PrincipalUri.StartsWith("principals/users/", StringComparison.Ordinal)
                ? PrincipalUri["principals/users/".Length..]
                : PrincipalUri;
        }

        public sealed record Signal
        {
            [JsonPropertyName("prev")] public long Prev { get; init; }       // feed position BEFORE this frame
            [JsonPropertyName("cursor")] public long Cursor { get; init; }   // feed position after it
            [JsonPropertyName("truncated")] public bool Truncated { get; init; }
            [JsonPropertyName("refs")] public List<ChangeRef> Refs { get; init; } = new();
        }

        // ── State ───────────────────────────────────────────────────────────

        private const string MessageName = "sendent_sync"; // also served by GET /notify/config

        private readonly Uri _wsUrl;        // ws_url from GET /notify/config, e.g. wss://cloud.example.com/push/ws
        private readonly string _botUser;   // the service account
        private readonly string _appPassword;

        /// <summary>Highest fully processed cursor. TODO: persist per instance.</summary>
        private long _lastCursor;

        public SendentChangeFeedClient(Uri wsUrl, string botUser, string appPassword)
        {
            _wsUrl = wsUrl;
            _botUser = botUser;
            _appPassword = appPassword;
        }

        // ── Receiving loop ──────────────────────────────────────────────────

        /// <summary>Connect, authenticate, and receive frames until cancelled; reconnects with backoff.</summary>
        public async Task RunAsync(CancellationToken ct)
        {
            var backoff = TimeSpan.FromSeconds(1);

            while (!ct.IsCancellationRequested)
            {
                try
                {
                    using var ws = new ClientWebSocket();
                    await ws.ConnectAsync(_wsUrl, ct);

                    // notify_push handshake: two text frames, then "authenticated".
                    await SendTextAsync(ws, _botUser, ct);
                    await SendTextAsync(ws, _appPassword, ct);
                    if (await ReceiveTextAsync(ws, ct) != "authenticated")
                    {
                        throw new InvalidOperationException("notify_push authentication failed");
                    }

                    // A gap may have opened while we were disconnected.
                    await CatchUpFromLedgerAsync(ct);
                    backoff = TimeSpan.FromSeconds(1);

                    while (ws.State == WebSocketState.Open && !ct.IsCancellationRequested)
                    {
                        var frame = await ReceiveTextAsync(ws, ct);
                        await OnFrameAsync(frame, ct);
                    }
                }
                catch (OperationCanceledException) when (ct.IsCancellationRequested)
                {
                    return;
                }
                catch
                {
                    await Task.Delay(backoff + TimeSpan.FromMilliseconds(Random.Shared.Next(0, 1000)), ct);
                    backoff = TimeSpan.FromSeconds(Math.Min(backoff.TotalSeconds * 2, 300));
                }
            }
        }

        /// <summary>Filters and parses one frame; decides between inline refs and ledger catch-up.</summary>
        private async Task OnFrameAsync(string frame, CancellationToken ct)
        {
            // The socket also carries the bot's own notify_file/notify_activity/
            // notify_notification frames — ignore everything that is not ours.
            if (frame == MessageName)
            {
                // Body-less frame (very old notify_push): a bare hint.
                await CatchUpFromLedgerAsync(ct);
                return;
            }
            if (!frame.StartsWith(MessageName + " ", StringComparison.Ordinal))
            {
                return;
            }

            var signal = JsonSerializer.Deserialize<Signal>(frame[(MessageName.Length + 1)..]);
            if (signal is null)
            {
                return;
            }

            if (signal.Prev > _lastCursor || signal.Truncated)
            {
                // Dropped frames (the daemon buffers only 4 per connection) or an
                // over-cap signal: the ledger is the source of truth — page it.
                await CatchUpFromLedgerAsync(ct);
                return;
            }

            foreach (var changeRef in signal.Refs)
            {
                await HandleChangedCollectionAsync(changeRef, ct);
            }

            if (signal.Cursor > _lastCursor) // monotonic compare only; sequences are not gapless
            {
                _lastCursor = signal.Cursor;
                // TODO: persist _lastCursor; optionally POST /notify/ack?cursor=…
            }
        }

        // ── Hooks the Connector implements (intentionally empty) ────────────

        /// <summary>
        /// TODO: page GET /index.php/apps/sendentsynchroniser/api/1.0/notify/changes
        /// (Basic auth as the bot) with since = max(0, _lastCursor - reread_overlap)
        /// until has_more=false; dispatch each ref like below; advance _lastCursor.
        /// </summary>
        protected virtual Task CatchUpFromLedgerAsync(CancellationToken ct) => Task.CompletedTask;

        /// <summary>
        /// TODO: ref.Uid changed — enqueue a sync-collection for
        /// (ref.Uid, ref.CollectionType, ref.CollectionUri) using OUR stored token.
        /// </summary>
        protected virtual Task HandleChangedCollectionAsync(ChangeRef changeRef, CancellationToken ct) => Task.CompletedTask;

        // ── Websocket plumbing ──────────────────────────────────────────────

        private static Task SendTextAsync(ClientWebSocket ws, string text, CancellationToken ct)
            => ws.SendAsync(Encoding.UTF8.GetBytes(text), WebSocketMessageType.Text, endOfMessage: true, ct);

        private static async Task<string> ReceiveTextAsync(ClientWebSocket ws, CancellationToken ct)
        {
            var buffer = new byte[64 * 1024]; // a full 500-ref signal is ~45 KB
            var builder = new StringBuilder();
            while (true)
            {
                var result = await ws.ReceiveAsync(buffer, ct);
                if (result.MessageType == WebSocketMessageType.Close)
                {
                    throw new WebSocketException("server closed the connection");
                }
                builder.Append(Encoding.UTF8.GetString(buffer, 0, result.Count));
                if (result.EndOfMessage)
                {
                    return builder.ToString();
                }
            }
        }
    }
}
