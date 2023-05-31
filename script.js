// set up request limit stuff
let rateLimit = 10;
let rateCnt = sessionStorage.getItem('rateCnt');
if (!rateCnt) {
    // init the counter
    sessionStorage.setItem('rateCnt', '0');
} else if (parseInt(rateCnt) >= rateLimit) {
    // disable the form if rateLimit has been reached
    $('#evilButton').hide();
    $('form input').prop('disabled', true);
    $('form button').prop('disabled', true);
}

$(document).ready(function () {
    // get the apiKey
    let apiKey;
    fetch('.env')
        .then(response => response.text())
        .then(data => {
            apiKey = data.trim().substring(8);
        })
        .catch(error => {
            alert('No env file found. API key may not be set. Please contact owner.')
        })

    // Intercept the form submission
    $('form').submit(function (event) {
        // prevent the typicall behavior
        event.preventDefault();

        // get form data
        let query = $('#query').val().trim();
        let title = $('#title').val().trim();

        // verify there are not empty
        if (query === '' || title === '') {
            // handle
            alert('Please enter a query and a title. These cannot be null.');
            return;
        }

        // check the rateLimit
        rateCnt = parseInt(sessionStorage.getItem('rateCnt'));
        if (rateCnt >= rateLimit) {
            let userPIN = prompt('Rate Limit Exceeded. Please enter the PIN to reset the session:  ');
            if (userPIN === PIN) {
                // reset
                sessionStorage.setItem('rateCnt', '0');
                $('form input').prop('disabled', false);
                $('form button').prop('disabled', false);
            } else {
                alert('Incorrect PIN. Please contact server admin if problem continues. ');
                return;
            }
        }

        // increment the rateCnt
        rateCnt++;
        sessionStorage.setItem('rateCnt', rateCnt.toString());

        console.log(rateCnt);

        // check for an XSS attack
        if (isXSSAttack(query) || isXSSAttack(title)) {
            alert('POTENTIAL XSS ATTACK DETECTED! ALERT: MESSAGE HAS BEEN SENT TO ADMIN.');
            $('#evilButton').hide();
            $('#face').show();
            return;
        }

        // create an obj
        let requestData = {
            model: "gpt-3.5-turbo",
            messages: [
                {
                    role: "system",
                    content: "I want you to act as an expert in the field of " + title + ", including its purpose, scope, key requirements, and notable updates compared to previous editions. I will ask you a series of questions related to this and you will provide me with clear, concise, and accurate information, including how this code impacts various types of occupancies or scenarios depending on the topic. Also discuss the enforcement and compliance aspects. On occasion, you may need to provide insights into common challenges faced in implementing the code and strategies for ensuring effective adherence to " + title + ". Please limit your response to the specific information requested and avoid unnecessary details. If you are asked a question that is not related to this topic, please respond with 'Whoops! That's not in any code book I have read!'"
                },
                {
                    role: "user",
                    content: query
                }
            ]
        };

        let headers = {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + apiKey
        };

        console.log(headers);
        console.log(requestData);

        // Make the AJAX request
        $.ajax({
            type: 'POST',
            url: 'https://api.openai.com/v1/chat/completions',
            headers: headers,
            data: JSON.stringify(requestData),
            success: function (response) {
                console.log(response);
                //$('#output-text').empty();
                let messages = response.choices[0].message.content;
                // Set vars to get the token info
                let cTokens = response.usage.completion_tokens;
                let pTokens = response.usage.prompt_tokens;
                let tTokens = response.usage.total_tokens;

                // show the text
                $('#output-text').text(messages);
                // display the token costs
                $('#cTokens').text(cTokens);
                $('#pTokens').text(pTokens);
                $('#tTokens').text(tTokens);
            },
            error: function (xhr, status, error) {
                // handle
                console.error(error);
                console.log('An error has occurred: ' + error);
            }
        });
    });
});

// function to check for HTML TAGS IN INPUT
function isXSSAttack(input) {
    let pattern = /<[^>]*>?/;
    return pattern.test(input);
}