# Custom fields

Put together your own kinds of offer from fields, in the administration:
a hotel with stars, a price per night and Wi-Fi; an event with a date and a
venue. No code needed.

Enable it under **Administration → Extensions**. Only one extension is active at
a time, so this one replaces e.g. the freelancer extension while it is on.
Offers of these kinds are listed and asked about (messages to the provider);
they have no order flow.

## Using it

**Administration → Offer kinds → New kind of offer.** Give it a name in each
language, then add fields:

| Kind of field | Stored as | Filter | Sort |
|---|---|---|---|
| Short text, Long text | a text per language | text contains (short text only) | - |
| Number | a number (`12,5` and `12.5` both work) | from / to | yes |
| Choice from a list | one of the choices, named in each language | one choice | - |
| Yes / no | yes or no | only those with "yes" | - |
| Date | `YYYY-MM-DD` | from / to | yes |

Each field can be required, shown on the cards of a list, a filter and a sort.
One number field can be **the price**: it becomes the offer's "from" price
(core listings, price sort). The kind of a field cannot change afterwards;
make a new field instead. A kind of offer that offers still use cannot be
deleted; deleting a field deletes what offers entered in it.

On `/offers` visitors pick a kind (the choice appears as soon as there is more
than one kind of offer); the list then shows that kind's filters and sorts.

## How it works

* `Definitions` reads and changes the types and fields (tables
  `x_fields_type`, `x_fields_field` and the labels), `Values` the values
  (`x_fields_value`: one row per offer, field and language, with a text and a
  number column and an index on each, so filtering and sorting stay in SQL).
* `CustomOfferType` is one class for every kind: `OfferType` for the form and
  the offer page, `FilterableOfferType` for filters and sorts,
  `CardOfferType` for the cards. The kinds are registered with
  `Registrar::offerTypes()`, so the database is only asked when a type is
  first needed.
* The names of kinds and sorts are texts that exist only at run time;
  `Extension` hands them to `Translator::define()`.

## Tests

    php extensions/fields/tests/run.php

Drives the administration, the offer form and wizard, and the list with its
filters through the core's kernel on an in-memory SQLite database
(`tests/support/http.php`).
