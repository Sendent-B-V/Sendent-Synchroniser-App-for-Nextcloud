<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>
<div class="tabmenu">
	<a class="tablink active" id="tab_sendent_general">Sendent Sync</a>
</div>
<div class="Settingspage" id="sendent_settings" style="display:block;margin-top:30px">

	<form class="form" method="post" id="settingsform">
		<div class="settingTemplateDetailInclude section">

			<?php print_unescaped($this->inc('sections/consentFlow')); ?>

			<?php if ($_['activeUser']) { ?>
					<?php print_unescaped($this->inc('sections/retractConsent')); ?>
			<?php } ?>
			</div>
	</form>
</div>