# simple-templating

> Simple templating for PHP. You can replace strings and use basic PHP functions.

**Build status**

master: [![Build Status](https://travis-ci.com/Yakubko/simple-templating.svg?branch=master)](https://travis-ci.com/Yakubko/simple-templating)
[![Coverage Status](https://coveralls.io/repos/github/Yakubko/simple-templating/badge.svg?branch=master)](https://coveralls.io/github/Yakubko/simple-templating?branch=master)

dev: [![Build Status](https://travis-ci.com/Yakubko/simple-templating.svg?branch=dev)](https://travis-ci.com/Yakubko/simple-templating)
[![Coverage Status](https://coveralls.io/repos/github/Yakubko/simple-templating/badge.svg?branch=dev)](https://coveralls.io/github/Yakubko/simple-templating?branch=dev)

## Install

The recommended way to install is via Composer:

```
composer require yakub/simple-templating
```

## Example

Simple usage

```php
<?php
$output = \Yakub\SimpleTemplating\Replace::compile(
	'Hi {{userName}}, you selected this options {{fn.implode(', ', options)}}',
	[
		'userName' => 'Jakub'
		'options' => [
			'PHP', 'SQL'
		]
	]
);

echo $output; // Output: "Hi Jakub, you selected this options PHP, SQL"
```

## Parameters

```php
\Yakub\SimpleTemplating\Replace::compile($template, $scope, $flags);
```

-   <b>\$template</b> - String template using syntax
-   <b>\$scope</b> - Associative array
-   <b>\$flags</b> - Additional options for replacing - USE_URLENCODE - Every replaced string will be encoded by `rawurlencode()`

## Syntax

Available syntax examples

#### String data

Socpe data:

```php
["name" => "Jakub Miškech"]
```

-   '{{name}}' -> 'Jakub Miškech'
-   '{{fn.strtoupper(name)}}' - 'JAKUB MIŠKECH'
-   '{{name[0]}}' - 'J'
-   '{{fn.ucwords('hello world')}}' - 'Hello World'
-   '{{fn.urlencode(name)}}' - 'Jakub+Mi%C5%A1kech'

#### Time data

Scope data:

```php
["strtotimeValue" => "yesterday"]
```

-   '{{fn.date('Y-m-d H:i:s')}}' -> '2020-04-27 17:01:31'
-   '{{fn.time()}}' -> '1580140891'
-   '{{fn.strtotime(strtotimeValue)}}' -> '1579993200'
-   '{{fn.strtodate('yesterday')}}' -> '2020-04-26 00:00:00'
-   '{{fn.strtodate('yesterday', 'Y-m-d')}}' -> '2020-01-26'

#### Number data

Scope data:

```php
["numberA" => "1.4"]
```

-   '{{fn.round(1.5)}}' -> '2'
-   '{{fn.round(numberA)}}' -> '1'
-   '{{fn.rand(0, 100)}}' -> '41'

#### Array data

Scope data:

```php
[
	"map" => ["typePK" => "name"],
	"displayName" => "title",
	"values" => [1, 2, 3],
	"forExplode" => "hi,hello",
	"types" => [
		["name" => "php", "title" => "PHP: Hypertext Preprocessor",
		["name" => "pg", "title" => "PostgreSQL"]
	]
]
```

-   '{{fn.implode(', ', values)}}' -> '1, 2, 3, 4'
-   '{{values[0]}}' -> '1'
-   '{{fn.implode(', ', fn.array_column(statuses, 'title'))}}' -> 'PHP: Hypertext Preprocessor, PostgreSQL'
-   '{{fn.explode(',', forExplode)[1]}}' -> 'hello'
-   '{{statuses[0]['title']}}' -> 'PHP: Hypertext Preprocessor'
-   '{{statuses[1][displayName]}}' -> 'PostgreSQL'
-   '{{statuses[1][map.statusesPK]}}' -> 'pg'

## Functions

All available function. More in [php.net](http://php.net "php.net")

-   Number

    -   round, rand, pow, floor, abs

-   Date time

    -   time, date, gmdate, strtotime, strtodate\*

-   Array

    -   explode, implode, array_column

-   String
    -   trim, strlen, substr, strpos, strstr, sprintf, ucfirst, ucwords, strtoupper, strtolower, strip_tags, str_replace, urlencode, rawurlencode

## Arithmetic operators

Arithmetic operators are used with numeric values to perform common arithmetical operations, such as addition, subtraction, multiplication and division.

Socpe data:

```php
["done" => 9, "total" => 100, "float" => "5.4" ]
```

-   '{{1 + 2}}' -> '3'
-   '{{1 + 2 * 3}}' -> '7'
-   '{{(1 + 2) * 3}}' -> '9'
-   '{{(1.2 + 2,4) * float}}' -> '19.44'
-   '{{fn.round((done / total)*100, 2)}} %' -> '9 %'

## Conditions

Syntax allow use inline condition with using logical and comparison operators.

Socpe data:

```php
["success" => true, "ok" => "ok", "notOk" => "not ok" ]
```

-   '{{(success) ? ok : notOk)}}' -> 'ok'
-   '{{(! success) ? ok : notOk)}}' -> 'not ok'
-   '{{1 && (0 || success) ? ok : notOk)}}' -> 'ok'
-   '{{1 > 0 && 1 < 1 ? ok : notOk)}}' -> 'not ok'

## Advanced

You can use this syntax to get access to object value. This work only when you use in template one replacing syntax. Value will be stored in onlyOneParamValue attribute.

```php
<?php
$output = \Yakub\SimpleTemplating\Replace::compile(
	'{{types[1]}}',
	[
		"types" => [
			["name" => "php", "title" => "PHP: Hypertext Preprocessor",
			["name" => "pg", "title" => "PostgreSQL"]
		]
	]
);

echo $output; // Output: "" array isn't transformed to string
echo json_encode($output->onlyOneParamValue); // Output: "{"name":"pg","title":"PostgreSQL"}"
```
