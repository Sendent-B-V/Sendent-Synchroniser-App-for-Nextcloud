<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>

<div class="Settingspage" id="sendent_settings" style="display:block">
	<form class="form" method="post" id="settingsform">
		<div class="settingTemplateDetailInclude section">
			<h2>
				<?php p($l->t('Sendent Synchronizer')); ?>
			</h2>
			<?php print_unescaped($this->inc('sections/consentFlow')); ?>
		</div>
	</form>
</div>