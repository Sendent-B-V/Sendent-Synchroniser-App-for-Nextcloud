<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */

script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>

<form class="form" method="post" id="settingsform">

    <?php print_unescaped($this->inc('sections/general')); ?>

</form>

</div>
