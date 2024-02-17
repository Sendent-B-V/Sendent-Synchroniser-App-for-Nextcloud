<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>
<div class="tabmenu">
	<a class="tablink active" id="tab_sendent_general">Sendent Sync</a>
</div>
<div class="Settingspage" id="sendent_settings" style="display:block;margin-top:30px;margin-left:12px">

	<form class="form" method="post" id="settingsform">
		<?php print_unescaped($this->inc('sections/groupsManagement')); ?>
		<?php print_unescaped($this->inc('sections/license')); ?>
		<?php print_unescaped($this->inc('sections/settings')); ?>
	</form>
</div>