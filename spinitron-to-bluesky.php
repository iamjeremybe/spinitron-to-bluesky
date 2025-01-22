<?php
// Load WordPress functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/wp-load.php');

// Function to retrieve a value from the wp_options table
function getOption($key) {
    return get_option($key);
}

// Function to store a value in the wp_options table
function setOption($key, $value) {
    update_option($key, $value);
}

// Function to get the Bluesky session token
function getBlueskyToken() {
    $tokenData = getOption('bluesky_playlist_session_token');
    if ($tokenData) {
        $tokenData = json_decode($tokenData, true);
        if (!empty($tokenData['token']) && time() < $tokenData['expiry']) {
            return $tokenData['token']; // Return valid token
        }
    }
    return null; // No valid token found
}

// Function to store the Bluesky session token
function storeBlueskyToken($token, $expiry) {
    $tokenData = [
        'token' => $token,
        'expiry' => $expiry // Store token expiry time
    ];
    setOption('bluesky_playlist_session_token', json_encode($tokenData));
}

// Function to authenticate and get a new session token.
// Bluesky doesn't include an expiration date in its response, but we can calculate one.
// If I'm reading the documentation correctly, we should never hit the limit for createSession
// even if we retrieve a new token every 15 minutes: https://docs.bsky.app/docs/advanced-guides/rate-limits
// (published rate as of 2025/01/20 is 30 per 5 minutes/300 per day).
function authenticateToBluesky($username, $password) {
    $authEndpoint = 'https://bsky.social/xrpc/com.atproto.server.createSession';
    $expiry = "+15 minutes";

    $authPayload = json_encode([
        'identifier' => $username,
        'password' => $password
    ]);

    $ch = curl_init($authEndpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $authPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        return [
            'token' => $responseData['accessJwt'],
            'expiry' => strtotime($expiry)
        ];
    }
    return null;
}

// Function to handle the form data and send to Bluesky.
// Other fields are available, but these are the only fields that are published to Discord, Icecast, etc.
// The metadata push from Spinitron has to include the first 3 fields; spinNote can be null.
function handleForm($formData, $sessionToken, $username) {
    // Extract form data
    $songName = $formData['songName'] ?? null;
    $artistName = $formData['artistName'] ?? null;
    $playlistTitle = $formData['playlistTitle'] ?? null;
    $spinNote = $formData['spinNote'] ?? null;

    if ($spinNote) {
        $spinNote = ' - ' . $spinNote;
    }

    // Construct the message
    $message = "Now playing on $playlistTitle: \"$songName\" by $artistName$spinNote";

    // Log the constructed message (for debugging)
    error_log("Constructed message: $message");

    // Send the post to Bluesky
    $apiEndpoint = 'https://bsky.social/xrpc/com.atproto.repo.createRecord';
    $payload = [
        "collection" => "app.bsky.feed.post",
        "repo" => $username, // Typically your DID
        "record" => [
            "text" => $message,
            "createdAt" => date('c')
        ]
    ];

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
    // Retrieve form data from x-www-form-urlencoded POST request
    $formData = $_POST;

    // Validate required fields
    if (empty($formData['songName']) || empty($formData['artistName']) || empty($formData['playlistTitle'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Required fields are missing']);
        exit;
    }

    // Get stored credentials
      $username = getOption('bluesky_playlist_username');
      $password = getOption('bluesky_playlist_password');

    if (!$username || !$password) {
        http_response_code(500);
        echo json_encode(['error' => 'Bluesky credentials are not set']);
        exit;
    }

    // Get or authenticate session token
    error_log("Checking for session token");
    $sessionToken = getBlueskyToken();
    if (!$sessionToken) {
        error_log("No session token found. Authenticating w/user+password");
        $authData = authenticateToBluesky($username, $password);
        if ($authData) {
            $sessionToken = $authData['token'];
            storeBlueskyToken($authData['token'], $authData['expiry']);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to authenticate with Bluesky']);
            exit;
        }
    }

    // Handle the form and post to Bluesky
    $response = handleForm($formData, $sessionToken, $username);
    echo $response;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method']);
}
?>
