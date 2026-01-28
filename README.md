# Very short description of the package

[![Latest Version on Packagist](https://img.shields.io/packagist/v/adichan/Fee.svg?style=flat-square)](https://packagist.org/packages/adichan/Fee)
[![Total Downloads](https://img.shields.io/packagist/dt/adichan/Fee.svg?style=flat-square)](https://packagist.org/packages/adichan/Fee)
![GitHub Actions](https://github.com/adichan/Fee/actions/workflows/main.yml/badge.svg)

This is where your description should go. Try and limit it to a paragraph or two, and maybe throw in a mention of what PSRs you support to avoid any confusion with users and contributors.

## Installation

You can install the package via composer:

```bash
composer require adichan/Fee
```

## Usage

```php
// Usage description here
```

### Testing

```bash
composer test
```

### Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

### Security

If you discover any security related issues, please email mobistyle35@gmail.com instead of using the issue tracker.

## Credits

-   [Adrian Radores](https://github.com/adichan)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

## Laravel Package Boilerplate

This package was generated using the [Laravel Package Boilerplate](https://laravelpackageboilerplate.com).


What is a global fee here?

````

// This is a GLOBAL fee - applies to EVERYONE by default
FeeRule::create([
    'entity_id' => null,        // No specific entity
    'entity_type' => null,      // No entity type
    'fee_template_id' => null,  // Not tied to a template
    'item_type' => 'product',

    'fee_type' => FeeType::MARKUP,

    'value' => 10.00,
]);

``php


```

item_type = 'product', 'service'

fee_type = 'commission', 'markup' , 'convenience'
 

```
