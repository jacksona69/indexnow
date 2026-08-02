# IndexNow Sitemap Submitter

A simple password-protected PHP utility that automatically reads your `sitemap.xml` and submits every URL to the IndexNow API.

## Features

- Reads every URL from sitemap.xml
- No URL maintenance
- Password protected
- Stores the last successful submission
- No database required
- Single PHP file

## Requirements

- PHP 7.4+
- cURL
- SimpleXML
- An IndexNow key

## Installation

1. Upload your IndexNow key file.
2. Edit:

```php
$siteName
$host
$key
$password
```

3. Upload `indexnow.php` to your website root.

4. Visit

```
https://example.com/indexnow.php
```

5. Log in.

6. Click **Submit Sitemap to IndexNow**.

## Licence

MIT



Created by Adam Jackson of [JaxMore Food & Drink Photography](https://jaxmore.com/).

If you find it useful, feel free to use or modify it.
