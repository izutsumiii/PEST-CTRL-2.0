<?php
session_start();
// Simulate maintenance mode for testing
$_SESSION['user_id'] = null; // Not logged in = will see maintenance
$shouldShowMaintenance = true;
$maintenanceMessage = 'This is a test of the maintenance page. Our developers are working hard!';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode - Site Under Maintenance</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inconsolata:wght@400;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
            background: #130325;
            color: #F9F9F9;
            font-family: 'Inconsolata', monospace;
            font-size: 100%;
            overflow-x: hidden;
        }

        .maintenance {
            text-transform: uppercase;
            margin-bottom: 1rem;
            font-size: 3rem;
            color: #FFD736;
            font-weight: 700;
        }

        .container {
            display: table;
            margin: 0 auto;
            max-width: 1024px;
            width: 100%;
            height: 100%;
            align-content: center;
            position: relative;
            box-sizing: border-box;
        }

        .what-is-up {
            position: absolute;
            width: 100%;
            top: 50%;
            transform: translateY(-50%);
            display: block;
            vertical-align: middle;
            text-align: center;
            box-sizing: border-box;
            padding: 20px;
        }

        .spinny-cogs {
            display: block;
            margin-bottom: 2rem;
        }

        .spinny-cogs .fa {
            color: #FFD736;
            margin: 0 10px;
        }

        .spinny-cogs .fa:nth-of-type(1) {
            -webkit-animation: fa-spin-one 1s infinite linear;
            animation: fa-spin-one 1s infinite linear;
        }

        .spinny-cogs .fa:nth-of-type(3) {
            -webkit-animation: fa-spin-two 2s infinite linear;
            animation: fa-spin-two 2s infinite linear;
        }

        .what-is-up h2 {
            font-size: 1.2rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            max-width: 600px;
            margin: 0 auto 2rem;
        }

        .maintenance-message {
            background: rgba(255, 215, 54, 0.1);
            border: 2px solid #FFD736;
            padding: 20px;
            border-radius: 8px;
            margin: 2rem auto;
            max-width: 600px;
            text-align: left;
        }

        .maintenance-message p {
            margin: 0;
            color: #ffffff;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-size: 1rem;
        }

        @-webkit-keyframes fa-spin-one {
            0% {
                -webkit-transform: translateY(-2rem) rotate(0deg);
                transform: translateY(-2rem) rotate(0deg);
            }
            100% {
                -webkit-transform: translateY(-2rem) rotate(-359deg);
                transform: translateY(-2rem) rotate(-359deg);
            }
        }

        @keyframes fa-spin-one {
            0% {
                -webkit-transform: translateY(-2rem) rotate(0deg);
                transform: translateY(-2rem) rotate(0deg);
            }
            100% {
                -webkit-transform: translateY(-2rem) rotate(-359deg);
                transform: translateY(-2rem) rotate(-359deg);
            }
        }

        @-webkit-keyframes fa-spin-two {
            0% {
                -webkit-transform: translateY(0.5rem) rotate(0deg);
                transform: translateY(0.5rem) rotate(0deg);
            }
            100% {
                -webkit-transform: translateY(0.5rem) rotate(-359deg);
                transform: translateY(0.5rem) rotate(-359deg);
            }
        }

        @keyframes fa-spin-two {
            0% {
                -webkit-transform: translateY(0.5rem) rotate(0deg);
                transform: translateY(0.5rem) rotate(0deg);
            }
            100% {
                -webkit-transform: translateY(0.5rem) rotate(-359deg);
                transform: translateY(0.5rem) rotate(-359deg);
            }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .maintenance {
                font-size: 2rem;
            }

            .what-is-up h2 {
                font-size: 1rem;
            }

            .spinny-cogs .fa {
                font-size: 2rem !important;
            }
        }

        @media (max-width: 576px) {
            .maintenance {
                font-size: 1.5rem;
            }

            .what-is-up h2 {
                font-size: 0.9rem;
            }

            .spinny-cogs .fa {
                font-size: 1.5rem !important;
                margin: 0 5px;
            }

            .maintenance-message {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="what-is-up">
            <div class="spinny-cogs">
                <i class="fa fa-cog" aria-hidden="true"></i>
                <i class="fa fa-5x fa-cog fa-spin" aria-hidden="true"></i>
                <i class="fa fa-3x fa-cog" aria-hidden="true"></i>
            </div>
            <h1 class="maintenance">Under Maintenance</h1>
            <h2>Our developers are hard at work updating your system. Please wait while we do this. We have also made the spinning cogs to distract you.</h2>
            <div class="maintenance-message">
                <p><?php echo htmlspecialchars($maintenanceMessage); ?></p>
            </div>
        </div>
    </div>
</body>
</html>

