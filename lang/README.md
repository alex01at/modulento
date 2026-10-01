Texts of this installation. A file `<language code>.php` here is read after
the core's and the extensions' language files and may contain any key, so it
can reword single texts or supply a whole language the core does not ship:

```php
<?php

return [
    'core.home.title' => 'Willkommen auf unserem Marktplatz',
];
```

Updates never touch this folder. To offer a new language, the core needs a
file for it as well (`core/lang/<code>.php`); enable it under
Administration → Settings.
