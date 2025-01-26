# spinitron-to-bluesky
A solution written in PHP (and some prototypes written in Python) that enable Bluesky to be added as a Channel for Spinitron's Metadata Push. The PHP solution also assumes it will be installed on a Wordpress site, with access to the Wordpress database.

## Background
Along with many other non-commercial stations, [KFAI](http://kfai.org) uses [Spinitron](https://spinitron.com/) for playlist management. Spinitron has built-in metadata publishing capabilities--for instance, if you are listening to the station with an HD-capable radio, the artist name and song title are pushed to that service from Spinitron.

Twitter was one of those channels, until automated services were largely disabled on that platform in 2023. An interest in resurrecting an account to publish KFAI's playlist on Bluesky rose with the recent waves of migration away from Twitter/X and Meta products to Bluesky. As one of those interested volunteers--and because KFAI is a volunteer-driven station--I began researching what it would take to make this work.

I am a long-time volunteer at the station, as a producer and occasional on-air sub host. My professional career to date has focused on software engineering and analytics. So using some of my "weekday" talents to accomplish something for the station has been a treat. My software engineering experience has primarily been with back-end, larger volume ETL and batch processes--I have more recent experience in exchanging data via APIs, but have not ever been tasked with web development.

So my initial tasks were:
* Learn about Spinitron and how its metadata push service works;
* Learn about Bluesky and how to post to its platform via an automated service;
* Decide what solutions will work best, given the station's needs and existing environment.

### Spinitron
Spinitron's metadata push service allows a station to define a channel, then craft a customizable URL to push information to that channel.
I confirmed that a simple push directly from Spinitron to Bluesky was not possible by [asking Spinitron's maintainers](https://forum.spinitron.com/t/metadata-push-to-bluesky/1477), and reading [Spinitron's metadata push documentation](https://forum.spinitron.com/t/metadata-push-guide/144). 

### Bluesky
Bluesky is built on [the AT protocol](https://atproto.com/guides/faq). Authentication is accomplished by first obtaining a token using a username+password, then using that token to publish content. 
This app accepts the metadata push from Spinitron, then authenticates and publishes that metadata to Bluesky. It also maintains the Bluesky account's access/refresh token, which can be used repeatedly until it expires. Bluesky limits the number of times an account user+password can be used to authenticate, so maintaining the token is a requirement for a service that may be publishing as frequently as 20 or more times per hour.

## Solutions
### Python+Flask
While I waited to connect with the station's web team, I began building some prototypes. My first attempt was using Python+Flask, because I'm comfortable coding in Python, and because Python examples of accepting a POST request, and authenticating and publishing to Bluesky, were included in the documentation for Flask and the AT protocol.

The Python prototype here, post_to_bluesky.py, more or less works, but it ended up not being the ultimate solution. I would have spent more time hardening this version against attacks--I implemented a Flask option to limit the number of times the script could be invoked, and started testing the option to limit the IP addresses from which this script could be called. There are other types of attacks I would have wanted to ensure this script can handle (cross-site scripting?).

I also looked at building a Docker image for this script to run on, to further isolate its environment.

### AWS Lambda
One of the Spinitron developers suggested a stateless function to perform this relay task, so I looked into AWS Lambda. I quickly realized that I could receive a POST request no problem, but building the necessary environment, securely storing credentials, and maintaining the access/refresh token required me to attach services that incurred costs. Still not knowing anything about the station's web platform, I opted to stop working on this version at that point.  So the script here, aws_lambda_receive_http_post_request.py, is only half of a solution. 

To complete this version, I would have needed a service like Secrets Manager (perhaps through the Systems Manager Parameter Store) to store user+password, and the strings representing the access and refresh token.

The AT Protocol module for Python is not available in the AWS Lambda environment, but there is an option to upload a .zip file containing additional modules, along with the script for your Lambda function. As I understood it, this would require attaching an S3 bucket for storage.
Costs would likely be low, but again, this was a lot of setup for a relatively simple task, and I wasn't sure it was the best solution.

### PHP
I shared my proposed solutions with KFAI's web developer when we were able to connect. The station's website is built using Wordpress, so his proposed solution was to use PHP instead, and store credentials in the Wordpress database. I did not know PHP at all, so I watched a few short videos to learn the basic syntax, then engaged ChatGPT to help me convert my working Python+Flask prototype into a PHP version, using [Wordpress' wp_options table](https://codex.wordpress.org/Database_Description) to store credentials.

##Testing
ChatGPT helpfully generated the PHP code, but I was still left with the task of testing it. After some research, I settled on a local installation of XAMPP, and I created a small web form to stand in as the Spinitron service.

My initial test worked! So I excitedly pushed my code to the website and enabled the new channel in Spinitron. It worked for about three hours, then stopped.

ChatGPT did me dirty and generated bad code for that expiry check in the second step. The script was only authenticating to Bluesky with user+password, reached its 100-login limit, and was disabled on Bluesky's end. This is where I sheepishly admit I was a little too excited and didn't fully test the various authentication statuses, which I then laid out:
* No token exists, or token is fully expired: use user+password
* Access token exists and hasn't yet expired: use it
* Access token exists, but has expired: use refresh token to obtain a new access token

So I came back the next morning with fresh eyes, fixed the bug, added more detailed logging, and performed more thorough testing of each of the three scenarios above. This second iteration has been working steadily 24/7 for days now.

## Future enhancements
* When manual updates to a playlist entry are made in Spinitron, it will send a new push of that entry's information. Sometimes the changes are made to fields that aren't published to Bluesky (album title, release date, label, etc.), so the new Bluesky post contains all of the same data as the previous post. I've been thinking about possibly storing the fields of the most recent post, and not publishing incoming pushes if all of the fields match. The only thing stopping me is the understanding that some KFAI DJs have actually played the same song multiple times in a row. The most recent instance of this that I recall was to persuade listeners to contribute to the station's member drive (looking at you, Ron).
* Spinitron often has cover art in its database--you can see examples of this on the feed on their homepage. It'd be great to publish this cover art to Bluesky. 
