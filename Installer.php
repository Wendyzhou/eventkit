<?php
/* ==========================================================================
 * INSTALLER
 * ==========================================================================
 *
 * SUMMARY
 * This script takes care of the initial installation of the
 * SendGrid Event Webhook Starter Kit
 *
 */

session_start();


$http = empty( $_SERVER['HTTPS'] ) ? 'http://' : 'https://';

?>

<html>
<head>
    <title>SendGrid Event Webhook Starter Kit Setup</title>

    <!-- STYLES -->
    <style type="text/css">
        #bg {
            background: #EFEFEF;
            position: absolute;
            left: 0px;
            right: 0px;
            top: 0px;
            bottom: 0px;
        }

        .error {color: #FF0000;}

        #container {
            width: 800px;
            height: 400px;
            left: 50%;
            top: 50%;
            margin-left: -400px;
            margin-top: -200px;
            position: absolute;
        }
    </style>
    <link rel="stylesheet" href="assets/application/css/application.css">
    <link rel="stylesheet" href="assets/vendor/css/vendor.css">

<?php


// We've already set things up - so redirect to the index page.
if ( $_SESSION['alreadysetup'] == true and file_exists( 'db' ) and is_dir( 'db' ) and file_exists( 'Constants.php' ) ) {

?>
    <script>
        $(function() {
            window.location= "index.php";
        });
    </script>
<?php
    die();
} else {
    include_once "Logger.php";
    include_once "DatabaseController.php";

?>

</head>
<body>
<div id="bg">

<?php

    if ( !empty( $_POST['username'] ) && !empty( $_POST['password'] ) ) {
        // CREATE A NEW DATABASE INSTANCE
        SendGrid\EventKit\DatabaseController::createNewDatabase();

        // STORE THE ENTERED DATA FOR LATER HTACCESS WRITING
        $username             = trim( $_POST["username"] );
        $password             = trim( $_POST["password"] );
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
        $_SESSION['eventurl'] = $http . $_SESSION['username'] . ':' . $_SESSION['password'] . '@' . $_SERVER['HTTP_HOST'] . dirname( $_SERVER['PHP_SELF'] ).'/index.php';

        $alreadysetup = true;
        $_SESSION['alreadysetup'] = $alreadysetup;


?>

    <script>
        window.location = "Step2Installer.php";
    </script>

<?php

    } else {

        // already setup so go to dashboard
        $alreadysetup = false;
        $_SESSION['alreadysetup'] = $alreadysetup;

        // define variables and set to empty values
        $usernameErr = $passwordErr = "";

        if ( isset( $_POST["username"] ) && isset( $_POST["password"] ) ) {
            if ( empty( $_POST["username"] ) ) {
                $usernameErr = "* Username is required";
            }

            if ( empty( $_POST["password"] ) ) {
                $passwordErr = "* Password is required";
            }
        }

    }
}
?>

    <div id="container">
        <div class="panel panel-primary">
            <div class="panel-heading">
                <img src="images/brand.png" />
            </div>
            <div class="panel-body">
                <p>Welcome to the SendGrid Event Webhook Starter Kit Installer. You're almost ready to start - but first you'll need to choose a username and password.  This will create an HTTP Basic Authentication credential to add a basic layer of security.  Since this starter kit is meant as an example, it's strongly recommended that you look into better security options since your database is behind a basic username and password.</p>
                <form role="form" action="" method="POST">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" class="form-control" id="username" name="username" placeholder="Username">
                        <span class="error"><?php echo $usernameErr;?></span>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Password">
                        <span class="error"><?php echo $passwordErr;?></span>
                    </div>

                  <button type="submit" class="btn btn-default">Proceed to Next Step</button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
