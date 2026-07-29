Thank you for contributing!

Guidelines

- Fork the repository and open a pull request against `main`.
- Keep changes small and focused; one feature or bugfix per PR.
- Add or update unit tests for any behavior change.
- Follow PSR-12 coding style.

Running tests

1. From the module root, install the development dependencies:

   composer install --prefer-dist --no-interaction

2. Run the unit test suite:

   vendor/bin/phpunit Test/Unit

3. If you are working with a Magento 2 instance using the Mark Shust Docker setup, run the tests from the Magento project root with:

   cd /root/magento-project
   ./bin/clinotty bash -lc '/var/www/html/vendor/bin/phpunit /var/www/html/app/code/StackNuts/CloudflareCache/Test/Unit --debug'

4. For a Magento integration environment, install the module into a Magento project and run the relevant Magento test commands from there.

Reporting issues

- Open issues at: https://github.com/StackNuts/magento-cloudflare-cache/issues
