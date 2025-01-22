from flask import Flask, abort, request
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address

import logging
import json
from atproto import Client, Session, SessionEvent
from typing import Optional

# SESSION_FILE will serve as storage for a Bluesky authentication token. It can be reused until it expires.
SESSION_FILE='session.txt'

# CREDS_FILE should contain a JSON object in this format:
# {"user": "Bluesky account name", "password": "Bluesky password"}
CREDS_FILE='creds.txt'

# Don't let any Internet rando submit posts to our endpoint
ALLOWED_IPS = ['15.235.50.214','51.161.118.109'] # IP addresses returned by an nslookup of spinitron.com

# Set up logging
logging.basicConfig(level=logging.INFO,  # You can use DEBUG, INFO, WARNING, ERROR, CRITICAL
                    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s',
                    handlers=[
                        logging.StreamHandler(),  # Log to stdout (Docker captures this)
                        logging.FileHandler("app.log")  # Log to a file
                    ])

# Create a logger instance
logger = logging.getLogger(__name__)

# Create the Flask app and set default rate limit
app = Flask(__name__)
limiter = Limiter(
            get_remote_address,
            app=app,
            default_limits = ["1 per second"],
            storage_uri = "memory://",
        )

# Functions
@app.before_request
def limit_remote_addr() -> None:
    client_ip = str(request.remote_addr)
    valid = False
    for ip in ALLOWED_IPS:
        if client_ip.startswith(ip) or client_ip == ip:
            valid = True
            break
    if not valid:
        logger.error(f"Bad request from IP: {request.remote_addr}")
        abort(403)

def get_session() -> Optional[str]:
    try:
        with open(SESSION_FILE) as f:
            return f.read()
    except FileNotFoundError:
        return None

def save_session(session_string: str) -> None:
    with open(SESSION_FILE,'w') as f:
        f.write(session_string)

def on_session_change(event: SessionEvent, session: Session) -> None:
    logger.info(['Session changed:', event, repr(session)])
    if event in (SessionEvent.CREATE, SessionEvent.REFRESH):
        logger.info('Saving changed session')
        save_session(session.export())

def retrieve_creds(cred_file: str) -> dict:
    try:
        with open(cred_file,'r') as creds:
            return json.load(creds)
    except FileNotFoundError:
        return None

def init_client() -> Client:
    client = Client()
    client.on_session_change(on_session_change)

    session_string = get_session()
    if session_string:
        logger.info('Reusing session')
        client.login(session_string=session_string)
    else:
        logger.info('Creating new session')
        creds = retrieve_creds(CREDS_FILE)
        client.login(creds['user'],creds['password'])
    return client

@app.route('/submit', methods=['POST'])
@limiter.limit("5/minute") # Limits requests to 5 per minute
def handle_form() -> None:
    # Get the data sent via x-www-form-urlencoded
    form_data = request.form

    # Process the form data (access individual fields using their names)
    sn = form_data.get('songName')
    an = form_data.get('artistName')
    wn = form_data.get('playlistTitle')
    spgia = form_data.get('timestamp')
    se = form_data.get('spinNote')

    if se:
        se = ' - '+se

    logger.debug(f"RECEIVED Now playing on {wn}: \"{sn}\" by {an}{se}")

    client = init_client()
    logger.info('Client is ready to use!')

    post = client.send_post(f"Now playing on {wn}: \"{sn}\" by {an}{se}")
    logger.debug([post.uri,post.cid])
    return post.uri

# Main
if __name__ == '__main__':
    # Run the Flask web server
    app.run(debug=False, host='0.0.0.0', port=19030)
