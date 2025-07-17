
In the current version - if you make several requests per minute - you will catch the captcha.
Captcha recognition is not implemented here.
Just wait and repeat the request in a couple of minutes.
But we managed to ensure that the server does not block our parser by IP.

It is possible to add several proxies
But the task is difficult because it is necessary to remember your cookies for each IP
And the parser sometimes makes several successful requests in a row from one IP

# STATEMENT OF THE PROBLEM
COPART server protection
1. every 5 minutes check whether the client supports JS - obfuscated JS code is slipped in, which calculates the session key and throws it into cookies
2. the session key is tied to the IP
3. after successful generation of the session key, the client from this IP can make requests without cookies at all for a few minutes
4. if the server doesn't like something - it blocks by IP for a couple of hours
5. the site is on Angular, there is a JSON API - from where we get the data on the lots

# iaai server protection
1. obfuscated JS code is slipped in, which calculates the session key and throws it into cookies
2. requests without a session key are prohibited
3. a site without JSON API (we parse data from HTML) lot data

# Options solutions Justification for choosing a solution
1. Connect a zombie browser on the server via Slenium (advantages - a full-fledged browser will bypass all protection, disadvantages - each request takes a few seconds until the browser is up)
2. On pure NodeJs with pappytear (Chromium) - disadvantage, you need to forward a port and raise a server on NodeJS to process requests
3. PHP + NodeJs (php makes the main requests, and if the protection is triggered, we call nodejs every five minutes)

# Algorithm for bypassing server protection COPART
1. We make a PHP request (with OUTGOING cookies that we received in paragraph 4. or without them if the zero step) we catch INCOMING cookies in file for future PHP requests
2. If the data is received - we display the RESULT
3. If the data is not received - we call Nodejs - with a high degree of probability we get the desired JSON - output the RESULT

Comment - why don't we pass cookies from pp 3 to pp 1 - it's magic. The server records a successful JS client in pp 3 and then trusts PHP in pp 1, which works with its cookies in file

# maybe it's also worth rewriting iaai to speed it up (it only works on NodeJs)