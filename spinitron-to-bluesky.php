<?php
// Alternate behavior for local testing--mostly finding alternatives to Wordpress functions
$TESTING=False;

// Make it easier to identify this script's actions in the server logs
$APP = 'post-playlist-to-bluesky';

// Load WordPress functions unless we're testing locally
if (!$TESTING) {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');
}

// Function to store the Bluesky session token
function storeBlueskyToken($accessJwt, $refreshJwt, $expiry) {
    global $TESTING;

    $tokenData = [
        'accessJwt' => $accessJwt,
        'refreshJwt' => $refreshJwt,
        'expiry' => $expiry
    ];
    if ($TESTING) {
        file_put_contents('bluesky_playlist_session_token',json_encode($tokenData));
    } else {
        update_option('bluesky_playlist_session_token', json_encode($tokenData));
    }
}

// Function to authenticate and get a new session token.
function authenticateToBluesky($username, $password) {
    $ch = curl_init('https://bsky.social/xrpc/com.atproto.server.createSession');
    $authPayload = json_encode([
        'identifier' => $username,
        'password' => $password
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $authPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if (!empty($responseData['accessJwt']) && !empty($responseData['refreshJwt'])) {
            return [
                'accessJwt' => $responseData['accessJwt'],
                'refreshJwt' => $responseData['refreshJwt'],
                'expiry' => time() + 3600 // Assume 1-hour token validity
            ];
        }
    }
    return null; // Authentication failed
}

//Function to refresh the Bluesky token
function refreshBlueskyToken($refreshJwt) {
    $ch = curl_init('https://bsky.social/xrpc/com.atproto.server.refreshSession');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $refreshJwt
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if (!empty($responseData['accessJwt']) && !empty($responseData['refreshJwt'])) {
            return [
                'accessJwt' => $responseData['accessJwt'],
                'refreshJwt' => $responseData['refreshJwt'],
                'expiry' => time() + 3600 // Assume 1-hour token validity
            ];
        }
    }

    return null; // Failed to refresh token
}

// Function to get the Bluesky session token
function getBlueskyToken($username, $password) {
    global $TESTING, $APP;

    $tokenData = '';
    if ($TESTING) {
        // Read the file back into memory
        $fileContents = file_get_contents('bluesky_playlist_session_token');

        // Decode the JSON back into an associative array
        $tokenData = json_decode($fileContents, true);
    } else {
        $tokenData = get_option('bluesky_playlist_session_token');
    }

    if (!empty($tokenData['accessJwt']) && time() < $tokenData['expiry']) {
        error_log("$APP Found a valid access token!");
        // Return valid access token
        return $tokenData['accessJwt'];
    }

    // Check if refresh token is available
    if (!empty($tokenData['refreshJwt'])) {
        error_log("$APP Access token is no longer valid. Attempting to use refresh token...");
        $newTokenData = refreshBlueskyToken($tokenData['refreshJwt']);
        if ($newTokenData) {
            error_log("$APP Succesfully refreshed the access token! Storing and returning it.");
            storeBlueskyToken($newTokenData['accessJwt'], $newTokenData['refreshJwt'], $newTokenData['expiry']);
            return $newTokenData['accessJwt'];
        }
    }

    // Fallback: Authenticate using username/password
    $authData = authenticateToBluesky($username, $password);
    if ($authData) {
        storeBlueskyToken($authData['accessJwt'], $authData['refreshJwt'], $authData['expiry']);
        return $authData['accessJwt'];
    }

    return null; // Authentication failed
}

function retrieveImage($imageUrl) {
    global $APP;

    // Retrieve a larger resolution image by altering the URL Spinitron sent.
    // The URL Spinitron provides for cover art defaults to a tiny 170x170 pixels.
    // This can be substituted for a larger 600x600 image.
    $updatedUrl = str_replace("170x170bb.jpg", "600x600bb.jpg", $imageUrl);

    error_log("Retrieving URL: $updatedUrl");
    $ch = curl_init($updatedUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);  // Return image data instead of outputting it
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);  // Follow redirects if necessary
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);           // Set timeout in seconds
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);  // Verify SSL certificate
    $imageData = curl_exec($ch);

    // Check for errors
    if (curl_errno($ch)) {
        error_log("cURL Error: " . curl_error($ch));
        return null;
    }
    curl_close($ch);

    if (!$imageData) {
        return null; // Failed to download image
    }

    // Get image type
    $imageInfo = getimagesizefromstring($imageData);
    if (!$imageInfo) {
        return null; // Not a valid image
    }

    $mimeType = $imageInfo['mime'];
    $originalWidth = $imageInfo[0];
    $originalHeight = $imageInfo[1];

    $image = imagecreatefromstring($imageData);

    if (!$image) {
        return null; // Failed to process image
    }
    error_log("$APP Found an image with dimensions $originalWidth, $originalHeight");

/* 
   I read a warning in the Bluesky documentation 
   that images with intact metadata may eventually be disallowed.
   If that ever happens, this code should strip metadata and allow us to post.
   It comes at the expense of some time spent on file conversion,
   so until it's obligatory, skip this step. 
*/
/*  ob_start();
    if ($mimeType === 'image/jpeg') {
        imagejpeg($image, null, 90); // Adjust quality as needed
    //} elseif ($mimeType === 'image/png') {
    //    imagepng($image, null, 9);
    } else {
        return null; // Unsupported image format
    }
    $cleanImageData = ob_get_clean();
*/
    $cleanImageData = $imageData; //Remove this line if metadata-stripping code is used
    
    // Free memory
    imagedestroy($image);
    return $cleanImageData;
}

function uploadImageToBluesky($imageUrl, $accessJwt) {
    // Download and strip metadata
    $cleanImageData = retrieveImage($imageUrl);
    if (!$cleanImageData) {
        return null; // Failed to process image
    }

    // Get MIME type again since data is now cleaned
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($cleanImageData);

    // Upload to Bluesky
    $ch = curl_init('https://bsky.social/xrpc/com.atproto.repo.uploadBlob');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $accessJwt,
        'Content-Type: ' . $mimeType
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cleanImageData);

    $response = curl_exec($ch);
    curl_close($ch);

    $responseData = json_decode($response, true);
    return $responseData['blob'] ?? null;
}

function constructBlueskyPostWithImage($message, $blob, $alttext, $username) {
    // Construct the post payload with an embedded image
    $payload = [
        'repo' => $username,
        'collection' => 'app.bsky.feed.post',
        'record' => [
            'text' => $message,
            'createdAt' => date('c'),
            'embed' => [
                '$type' => 'app.bsky.embed.images',
                'images' => [
                    [
                        'alt' => $alttext,
                        'image' => $blob
                    ]
                ]
            ]
        ]
    ];

    return $payload;
}

function constructBlueskyPost($message,$username) {
    $payload = [
        'collection' => "app.bsky.feed.post",
        'repo' => $username,
        'record' => [
            'text' => $message,
            'createdAt' => date('c')
        ]
    ];

    return $payload;
}

// Function to handle the form data and send to Bluesky.
// Other fields are available, but these are the only fields that are published to Discord, Icecast, etc.
// The metadata push from Spinitron has to include the first 3 fields; spinNote and coverArt can be null.
function handleForm($formData, $sessionToken, $username) {
    global $TESTING, $APP;
    // Extract form data
    $songName = stripslashes($formData['songName']) ?? null;
    $artistName = stripslashes($formData['artistName']) ?? null;
    $playlistTitle = stripslashes($formData['playlistTitle']) ?? null;
    $spinNote = stripslashes($formData['spinNote']) ?? null;
    $coverArt = stripslashes($formData['coverArt']) ?? null;

    if ($spinNote) {
        $spinNote = ' - ' . $spinNote;
    }

    // Construct the message
    $message = "Now playing on $playlistTitle: \"$songName\" by $artistName$spinNote";

    // Log the constructed message (for debugging)
    error_log("$APP Constructed message: $message");

    // Retrieve the cover art (optional--we'll post without if we don't successfully retrieve it)
    if ($coverArt) {
        $image = uploadImageToBluesky($coverArt, $sessionToken);
        if ($image) {
            $alttext = "\"$songName\" by $artistName";
            $payload = constructBlueskyPostWithImage($message,$image, $alttext, $username);
        }
    } else {
        $payload = constructBlueskyPost($message,$username);
    }

    // Send the post to Bluesky
    $apiEndpoint = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';
    $ch = curl_init($apiEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $sessionToken
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        return json_encode(['success' => true, 'message' => 'Post published successfully']);
    } else {
        return json_encode(['success' => false, 'response' => $response]);
    }
}

// Main script logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    global $TESTING, $APP;

    // Retrieve form data from x-www-form-urlencoded POST request
    $formData = $_POST;

    // Validate required fields
    if (empty($formData['songName']) || empty($formData['artistName']) || empty($formData['playlistTitle'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Required fields are missing']);
        exit;
    }

    // Get stored credentials
    if (!$TESTING) {
        $username = get_option('bluesky_playlist_username');
        $password = get_option('bluesky_playlist_password');  
    } else {
        $username = "username"; 
        $password = "password";  
    }

    if (!$username || !$password) {
        http_response_code(500);
        echo json_encode(['error' => 'Bluesky credentials are not set']);
        exit;
    }

    // Get or authenticate session token
    error_log("$APP Checking for session token");
    $sessionToken = getBlueskyToken($username, $password);
    if (!$sessionToken) {
        error_log("$APP Not able to find or create a session token!");
        http_response_code(500);
        echo json_encode(['error' => 'Failed to authenticate with Bluesky']);
        exit;
    }

    // Handle the form and post to Bluesky
    $response = handleForm($formData, $sessionToken, $username);
    echo $response;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method']);
}
?>