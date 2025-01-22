import logging
from urllib.parse import parse_qs
import json
import base64

# NEXT STEP: Repost the string I'm returning as data to the kfaiplaylist Bluesky account.
# There's no atproto module available here in AWS so I'd need to install it with this script as a .zip file.

logger = logging.getLogger()
logger.setLevel(logging.INFO)

def lambda_handler(event, context):
    logger.info(f"Event received: {event}")
    body = event.get('body', '')  # Get the raw body

    # Check if the body is Base64-encoded (AWS does this by default?)
    body = event.get('body', '')
    if event.get('isBase64Encoded', False):
        # Decode the Base64-encoded body
        body = base64.b64decode(body).decode('utf-8')
    
    parsed_body = parse_qs(body)  # Parse URL-encoded text
    parsed_body = {key: value[0] if len(value) == 1 else value for key, value in parsed_body.items()}
    logger.info(f"Parsed body: {parsed_body}")

    if parsed_body['spinNote']:
        parsed_body['spinNote'] = ' - '+parsed_body['spinNote']
    
    response = {
        "statusCode": 200,
        "headers": {
            "Content-Type": "application/json",
            "Access-Control-Allow-Origin": "*",
            "Access-Control-Allow-Methods": "POST",
            "Access-Control-Allow-Headers": "Content-Type"
        },
        "body": json.dumps({
            "message": "URL-encoded data received successfully",
            "data": f"Now playing on {parsed_body['playlistTitle']}: \"{parsed_body['songName']}\" by {parsed_body['artistName']}{parsed_body['spinNote']}"
        })
    }
    return response

