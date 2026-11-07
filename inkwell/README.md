# Inkwell Books: e-book store (HTML, CSS, JS, PHP)

## Run it
Open this folder in VS Code, open a terminal (Ctrl+`) and run:

    C:\xampp\php\php.exe -S localhost:8000 -t public

Then visit http://localhost:8000 . (Mac: /Applications/XAMPP/xamppfiles/bin/php. If PHP is on your PATH, just use `php`.)
If you see "could not find driver", enable `extension=pdo_sqlite` and `extension=sqlite3` in php.ini.

## Pages
- index.html: catalogue and cart. login.html: sign in / create account.
- checkout.html: payment (requires sign-in). account.html: order history and downloads.

## Edit
- Books and prices: public/data/books.json. Styling: variables at the top of public/css/style.css.
- Real e-book files: private/books/<book-id>.pdf or .epub.
- To start with a clean database, stop the server and delete private/store.sqlite.

Test cards: 4242 4242 4242 4242 (approved), 4000 0000 0000 0002 (declined), 4000 0000 0000 9995 (insufficient funds). Any future expiry, any 3-digit code.
Full documentation is in the Word document supplied with the project.
