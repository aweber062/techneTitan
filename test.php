<?php
// Error reporting and logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'C:\MAMP\htdocs\AI_BOT\error.log');

// Enable Strict Transport Security
header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
/**
 * HSTS: 'Strict-Transport-Security' header is set to enforce the use of secure HTTPS
 * connections by instructing the browser to only communicate with server over HTTPS.
 * The max-age directive specifies the duration (in seconds), for which the browser should remember to
 * enforce HTTPS. The 'includeSubDomains' extends the policy to all subdomains and preload
 * indicates that the site should be included in the 'browser's HSTS preload list.
 **/

// Set a rate limit
$rateLimit = 10; // max num of requests per allowed time frame
$timeFrame = 3600; // one hour until you have to refresh
$maxQueryLength = 150; // max length for query field
$maxTitleLength = 100; // max length for the title field.

// FIGURE OUT THE MAX TOKENS
$maxTokens = 215;

// start the session
session_start();

// make file path
$path = __DIR__ . '/.env';
// load the .env file
if (file_exists($path)) {
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        list($name, $value) = explode('=', $line, 2);
        $_ENV[$name] = $value;
    }
}
// Check if the form has been submitted:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // load up the fun ratelimit
    if (!isset($_SESSION['requests'])) {
        // if no requests have been made yet, init the session var.
        $_SESSION['requests'] = 1;
        $_SESSION['timestamp'] = time();
    } else {
        // if requests have been made, check the rate limit.
        $timestamp = $_SESSION['timestamp'];
        $elapsedTime = time() - $timestamp;

        if ($elapsedTime < $timeFrame) {
            // if the time frame have not elapsed, increment the request count
            $_SESSION['requests']++;
        } else {
            $_SESSION['requests'] = 1;
            $_SESSION['timestamp'] = time();
        }
    } // end of rateLimit stuff :D

    try {
        // FILTER QUERY, THE QUESTION OF THE USER.
        $query = isset($_POST['query']) ? trim($_POST['query']) : '';
        $query = filter_var($query, FILTER_SANITIZE_STRING);
        $query = substr($query, 0, $maxQueryLength);
        // FILTER TITLE, THE SOURCE OF THE CONTENT.
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $title = filter_var($title, FILTER_SANITIZE_STRING);
        $title = substr($title, 0, $maxTitleLength);


        // check if rate Limit has been exceeded:
        if ($_SESSION['requests'] > $rateLimit) {
            throw new BadMethodCallException("Error: Request Limit Exceeded. Please try again later. Or, contact server ADMIN if problems continues");
            //handleError("Error: Request Limit exceeded. Please try again later.");
        }

        // check the length
        if (strlen($_POST['query']) > $maxQueryLength || strlen($_POST['title']) > $maxTitleLength) {
            throw new Exception("Error: Input length exceeded the max limit");
            //handleError("Error: Input length exceeded the max limit.");
        }
        // validate the query field
        if (empty($query) || empty($title)) {
            // handle
            throw new Exception("Error: do not leave cells blank");
            //handleError("Error: Invalid Input");
        }

        // PREVENT XSS ATTACKS
        if (isset($_POST['query'])) {
            $query = htmlspecialchars($_POST['query'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($query !== $_POST['query']) {
                throw new OutOfBoundsException("ERROR: XSS ATTACK in QUERY");
                //handleError("Error: Special scripting: hackers are attacking!!!!!!!!!!!!!!!!");
            }
        }
        if (isset($_POST['title'])) {
            $title = htmlspecialchars($_POST['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($title !== $_POST['title']) {
                throw new OutOfBoundsException("ERROR: XSS ATTACK in TITLE");
            }
        }
    } catch (BadMethodCallException $badMethodCallException) {
        echo $badMethodCallException;
        exit;
    } catch (OutOfBoundsException $boundsException) {
        echo $boundsException;
        exit;
    } catch (Exception $exception) {
        echo $exception;
        exit;
    }
    /**
     * Start of the API request.
     */
    //OPENAI_KEY
    $apiKey = isset($_ENV['API_KEY']) ? $_ENV['API_KEY'] : '';
    if (empty($apiKey)) {
        echo "API KEY IS NOT SET, DO NOT CONTINUE OR REQUEST. PLEASE CONTACT SERVER ADMIN";
        exit;
        //handleError("Error: API key is not set");
    }
    // API ENDPOINT / HEADER
    # using chat/completions
    $url = "https://api.openai.com/v1/chat/completions";
    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    );
    // Prepare the payload for the API request:
    // gpt-3.5-turbo cost is $0.002 per 1K tokens.
    $data = array(
        "model" => "gpt-3.5-turbo",
        "messages" => array(
            // Content: Contains the actual text content of the message.
            // System: represents the behavior or context for the conversation.
            // User: represents a query like a question to GPT
            // Assistant: Represents GPT's response.

            // Some token stuff:
            // The cost of the system content is 176 tokens.
            // The cost of user content is 15 tokens
            // The total cost of sending the prompt is 191 tokens.
            array(
                "role" => "system",
                // Without the title, the prompt alone cost 165-175 tokens.
                // The prompt cost 185 alone with the: NFPA 101 Safety Code 2012 Edition.
                // So, with a title it can anywhere between 170 - 200 tokens.
                "content" => "I want you to act as an expert in the field of {$title}, 
                including its purpose, scope, key requirements, and notable updates compared to previous editions.
                I will ask you a series of questions related to this and you will
                provide me with clear, concise, and accurate information, including how this code 
                impacts various types of occupancies or scenarios depending on the topic. Also discuss the 
                enforcement and compliance aspects. On occasion, you may need to provide insights into common challenges
                faced in implementing the code and strategies for ensuring effective adherence to {$title}.
                Please limit your response to the specific information requested and avoid unnecessary details.
                If you are asked a question that is not related to this topic, please respond with 'Whoops! That's not in any code book I have read!'"
            ),
            array(
                "role" => "user",
                // Asking a question such as: What is a design fire scenario? is 15 tokens.
                "content" => $query
            )
        )
    );
    // Init cURL
    $ch = curl_init($url);

    // SET cURL options.
    curl_setopt($ch, CURLOPT_CAINFO, 'C:\MAMP\htdocs\AI_BOT\cacert.pem');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // execute api request
    $response = curl_exec($ch);

    // check for errors.
    if ($response === false) {
        // Handle error
        handleError("Error: cURL error - " . curl_error($ch));
        exit;
    } else {
        // Process the response data
        $responseData = json_decode($response, true);
        // Access the returned data as needed
        // var_dump($responseData);
        $generatedText = $responseData['choices'][0]['message']['content'];
        echo "Generated Text: " . $generatedText;
    }

    // Close cURL
    curl_close($ch);
} // end of method
function handleError($message)
{
    // log the error
    error_log($message);
    echo $message;
}

?>
<!--
<!DOCTYPE html>
<html>
<head>
    <title>TechneTitan</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f2f2f2;
            margin: 20px;
        }

        h1 {
            /* Put the Medxcel colors as a gradient for the title */
            background-image: linear-gradient(to right, #5877a4, #a2b8d7, #002d62, #6a2c91, #5f6369, #ac2650, #e59f40, #04b14f);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            font-size: 65px;
            font-weight: bold;
        }

        hr {
            border: none;
            height: 3px;
            background-color: black;
            width: 665px;
            margin: 0;
        }

        form {
            margin-top: 20px;
        }

        label {
            font-weight: bold;
        }

        input[type="type"] {
            width: 300px;
            padding: 5px;
            border: 1px solid #ccc;
            border-radius: 3px;
            margin-bottom: 10px;
        }

        #logo {
            width: 100px;
            height: 100px;
            overflow: hidden;
        }

        #logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        #container {
            display: flex;
            align-items: center;
        }

        #container img {
            margin-right: 10px;
        }

        #output-text {
            max-width: 665px;
            word-wrap: break-word;
            background-color: lightgreen;
            border-color: black;
            border-style: solid;

        }

        #breakingTheBudget {
            max-width: 600px;
            word-wrap: break-word;
        }

        #niceMessage {
            max-width: 600px;
            word-wrap: break-word;
        }

        #evilButton {
            background-color: green;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            font-size 16px;
            font-weight: bold;
            cursor: pointer;
        }

        #evilButton:hover {
            background-color: darkgreen;
        }
    </style>
</head>
<body>
<div id="container">
    <img id="logo" src="img/m_logo.png" alt="logo">
    <!-- TechneTitan means  Powerful Craft-->
    <!--- Pronouce: "TEK-nee-TYE-tan-->
    <h1>TechneTitan - 3.5</h1>
</div>
<hr>
<span style="color: red; font-style: italic; font-size: large"><h5>Warning: You only have 10 requests per hour. Unless you know the hack around it :D</h5></span>
<form method="POST" action="">
    <label for="query">Enter your query: </label>
    <br>
    <input type="text" name="query" id="query" oninput="calculateTokens()" required>
    <br><br>
    <label for="title">Enter the title of your source (be detailed as possible): </label>
    <br>
    <input type="text" name="title" id="title" oninput="calculateTokens()" required>
    <br><br>
    <div style="color: lightskyblue; font-weight: bold; font-style: italic; width: 600px; word-wrap: break-word;">Please
        be aware that the token counter just provides an estimate and may not be entirely accurate. The error margin is
        +/- 15 tokens.
    </div>
    <br>
    <div id="tokenCount" style="font-weight: bold;">Total Input-Token Cost: 0</div>
    <!-- THE DIVs BELOW CORRELATE TO THE WARNING MESSAGES-->
    <div id="breakingTheBudget" style="font-style: italic; font-size: x-large; color: red"></div>
    <br>
    <div id="niceMessage" style="font-style: normal; font-size: large; color: black"></div>
    <br>
    <!-- Happy face -->
    <img id="face" src="img/medxcel_happy_face.png" alt="happy" style="display: none" width="600px">
    <br><br>
    <button id="evilButton" type="submit">Go</button>
</form>
<!-- AJAX AND JQUERY TO HANDLE TOKENS :p -->
<script>
    function countTokens(text) {
        // tokenize the text using tokenization rules and patterns for the
        // gpt-3.5-turbo model.
        var pattern = /[^\p{L}\d\s]+|[\d]+(?:[.,][\d]+)?|\p{L}+/gu;
        // ^ adjust pattern above if needs be

        var tokens = text.split(pattern);
        return tokens.length;
    } // end of countTokens

    function calculateTokens() {
        var title = $("#title").val();
        var query = $("#query").val();

        var titleTokens = countTokens(title);
        var queryTokens = countTokens(query);

        // we know that the prompt is roughly 165-175, so we add 175 for the highest use case
        var totalInputTokenCost = (titleTokens * 2) + queryTokens + 175;

        // Clear out the extra 200 tokens :D
        if (title === "" && query === "") {
            totalInputTokenCost = 0; // reset the tokens
        } // end of magic conditional statement.

        // Ask Brandon what the MAX tokens should be.
        if (totalInputTokenCost >= <?/*= $maxTokens */?>) {
            $("#tokenCount").text("Total Input-Token Cost: " + totalInputTokenCost);

            // Call show, then text.
            $("#breakingTheBudget").show().text("Wow, wow, wow. Hold your horses, dear sir or madam! That is an astronomical number of tokens. Please take a moment to gather yourself and reconsider your token usage.");
            $('#niceMessage').show().text("Please try to keep your tokens under [the predetermined amount: <?/*= $maxTokens */?> tokens ]. In order to prevent Medxcel from going out of business (or Alex's personal funds :D). Remember that tokens are calculated based on the total number of characters, including but not limited to the; Query, Title (of your source), and Whitespace. Thank you. Your (truly) happy associates at Medxcel! ");
            // hide the evil button.
            $('#face').show();
            // Just hide everything if the token limit is hit.
            $('#evilButton').hide(); // hide the submit button so users can't submit the high cost query.
            $('#g-text').hide();
            $('#output-text').hide();
        } else {
            $("#tokenCount").text("Total Input-Token Cost: " + totalInputTokenCost);
            // Hide messages if the token limit has not been hit.
            $('#breakingTheBudget').hide();
            $('#niceMessage').hide();
            $('#face').hide();
            // submit button display :D and all other fields
            $('#evilButton').show();
            $('#g-text').show();
            $('#output-text').show();
        } // end of screaming at the user :p
    } // end of calculateTokens
</script>
<?php /*if ($generatedText !== ""): */?>
    <h2 id="g-text">Generated Text:</h2>
    <div id="output-text"><?php /*echo $generatedText; */?></div>
<?php /*endif; */?>
</body>
</html>
-->