<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>

<div class="Settingspage" id="sendent_settings" style="display:block">

	<form class="form" method="post" id="settingsform">

	    <?php print_unescaped($this->inc('sections/groupsManagement')); ?>
    </form>
</div>
