phpslack
========

PHP Class for using the Slack API from php-cli, for making a bot that can handle commands as well as 
external events.

Look at example.php for an example that does pretty much nothing except open a connection and keep it open.

This class requires two auth tokens for all functionality. To connect to the RTM API, an bot user auth token is 
required, and for most of the postapi functions an oauth2 token for an authenticated user is required (functions 
restricted by "user_is_bot" property need oauth2)



