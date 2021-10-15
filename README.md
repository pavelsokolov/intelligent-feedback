## auth.json

This file is required for this tool to work. It contains credentials for Mediasite/Daisy API access.

```json
{
    "play2":
        {
            "username": "username",
            "password": "password",
            "url": "apiurl",
            "sfapikey": "apikey"
        },
    "ilearn":
        {
            "url": "url",
	    "secret": "secret"
        }
}
```
## Usage
```bash
php getilearndata.php
php getvideostats.php
```
