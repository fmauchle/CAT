<?php
/* * *********************************************************************************
 * (c) 2011-15 GÉANT on behalf of the GN3, GN3plus and GN4 consortia
 * License: see the LICENSE file in the root directory
 * ********************************************************************************* */
?>
<?php
/**
 * 404 error handler
 *
 * @author Stefan Winter <stefan.winter@restena.lu>
 * @package UserGUI
 */
error_reporting(E_ALL | E_STRICT);
include(dirname(dirname(__FILE__)) . "/config/_config.php");
require_once("Language.php");
require_once("resources/inc/header.php");
require_once("resources/inc/footer.php");
$langObject = new Language();
$langObject->setTextDomain("web_user");

defaultPagePrelude(CONFIG['APPEARANCE']['productname_long'], FALSE);
?>
<link rel="stylesheet" media="screen" type="text/css" href="<?php echo dirname($_SERVER['SCRIPT_NAME'])?>/resources/css/cat-user.css"/>
</head>
<body>
    <div id="heading">
        <?php
        print '<img src="'. dirname($_SERVER['SCRIPT_NAME']) .'/resources/images/consortium_logo.png" alt="Consortium Logo" style="float:right; padding-right:20px; padding-top:20px"/>';
        print '<div id="motd">' . ( isset(CONFIG['APPEARANCE']['MOTD']) ? CONFIG['APPEARANCE']['MOTD'] : '&nbsp' ) . '</div>';
        print '<h1 style="padding-bottom:0px; height:1em;">' . sprintf(_("Welcome to %s"), CONFIG['APPEARANCE']['productname']) . '</h1>
<h2 style="padding-bottom:0px; height:0px; vertical-align:bottom;">' . CONFIG['APPEARANCE']['productname_long'] . '</h2>';
        echo '<table id="lang_select"><tr><td>';
        echo _("View this page in");
        ?>
        <?php
        foreach (CONFIG['LANGUAGES'] as $lang => $value) {
            echo "<a href='javascript:changeLang(\"$lang\")'>" . $value['display'] . "</a> ";
        }
        echo '</td><td style="text-align:right;padding-right:20px"><a href="' . dirname($_SERVER['SCRIPT_NAME']) . '?lang=' . $langObject->getLang() . '">' . _("Start page") . '</a></td></tr></table>';
        ?>
    </div> <!-- id="heading" -->
    <div id="main_body" style='padding:20px;'>
        <h1><?php echo _("This is not the CAT you are looking for.");?></h1>
        <p><?php echo _("Whatever you expected to see at this URL - it's not here. The only thing here is the number");?></p>
        <h2>404</h2>
        <p><?php echo sprintf(_("staring at you. Your mistake? Our error? Who knows! Maybe you should go back to the <a href='%s'>Start Page</a>."), dirname($_SERVER['SCRIPT_NAME']) . '?lang=' . $langObject->getLang());?></p>
    </div> <!-- id="main_body" -->

        <?php footer();
