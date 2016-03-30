# pd2jira

[![Deploy](https://www.herokucdn.com/deploy/button.png)](https://heroku.com/deploy)

This is a modification to the pd2jira integration.  This has a bit more bi-directional functionality.  It handles the following workflows:

- Create issue in JIRA -> Creates PD incident; Resolve PD incident -> Marks JIRA issue as Done.
- Create issue in JIRA -> Creates PD incident; Mark JIRA issue as Done -> Resolves PD incident (Default integration)
- Create PD incident -> Creates JIRA issue;  Resolve PD incident -> Marks JIRA issue as Done.  Please see the note below regarding the webhook configuration in JIRA.

The one workflow that does not work is when an incident is created in PD, then the associated JIRA issue is resolved in JIRA.  It will not resolve the associated PD incident.  This has to do with how the incident key is created.  The out of the box integration will resolve incidents in PD based on an incident key that is the issue ID.  If you create the PD incident first, it does not yet know what the JIRA issue ID is yet.  It's a chicken/egg problem.  This could likely be solved by adding another field to JIRA and using that to store the PD incident ID, but that's going to be out of the scope of what I can do for this project. 

Please note:  You will need to modify your webhook in JIRA so that duplicate PagerDuty incidents are not created.  You'll want to add the following JQL to your webhook configuration: ```description !~ "A new PagerDuty ticket as been created"```

You can host this yourself or deploy it to Heroku.  Either way, simply create a new webhook on your PagerDuty service(s) that points to the URL of the PHP script.  If using Heroku, you can fill out the necessary fields.  If you are hosting this yourself, you will need to populate the variables at the beginning of the PHP script.