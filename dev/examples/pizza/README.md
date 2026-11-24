# Pizza shop – an example custom page

A complete small app written as a nuBuilder Next **custom page**: customers order pizzas, the kitchen works the orders on a
board, the back office keeps the menu and looks at the day's numbers. Plain PHP, one folder, everything through `$nu`
(see `custom/PAGE_GUIDE.md`).

## Install

1. Forms → **New form or page** → **Custom page**: description `Pizza shop`, code `pizza`, open in *Inside nuBuilder Next*.
2. Copy this folder to `custom/pages/pizza/` of the installation (FTP, or the File Manager).
3. In the page editor: **Examples → settings.example.json**, iframe height e.g. `86vh`, Save.
4. Permission items → new item `PIZZA_BACKOFFICE` – give it to the users of the kitchen.
5. Access levels: give the levels of customers and kitchen the right to view the page; set it as their **Home form**
   (tab *Start page*) so they land in the shop after signing in.

The first visit creates the tables `pizza_menu`, `pizza_order`, `pizza_order_item` and a sample menu (MySQL / MariaDB;
`lib/schema.php` has the SQL Server variant too).

## What it shows

| Where | What |
| --- | --- |
| `index.php` | the router: JSON / POST actions, then one view; rights checked in every action |
| `lib/shop.php` | prices from the database (never from the browser), a transaction per order, conditional UPDATE for the kitchen steps, `$nu->notify()`, `$nu->audit()` |
| `lib/schema.php` | own tables on the first visit, `$nu->dbType()` |
| `lib/svg.php` | pizzas drawn from their toppings |
| `views/*.php` | one file per view, every value through `$nu->h()`, forms with `$nu->csrfField()` |
| `assets/app.js` | `NuPage.fetch()`, polling with `&passive=1`, the cart in sessionStorage |
| `settings.example.json` | opening hours, currency, delivery fee, minimum order, extras – read with `$nu->setting()` |

Roles: everybody who may open the page can order; the permission item `PIZZA_BACKOFFICE` adds the tabs Kitchen, Pizzas
and Today. In demo mode and while an administrator acts as another user the page is read only (`$nu->readOnly()`).

## Trying changes on a draft

Create a draft of the page and open it: it works on its own copy of `_data/`, but the orders go into the same database. To keep
test orders out of the kitchen, let the draft use its own tables, for example in `lib/schema.php`:

```php
$suffix = $nu->isDraft() ? '_test' : '';      // pizza_order_test while a designer tries the draft
```

For tests against another database, export the application with the page and import it on a test installation.
