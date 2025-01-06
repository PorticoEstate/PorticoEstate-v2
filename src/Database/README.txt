FakePDOOCI and FakePDOOCIStatement are classes that mimic the behavior of PDO and PDOStatement classes.
They are used to test the Database class if pdo_oci is missing (temporary build-problem for php8.4).
They are not meant to be used in production code.