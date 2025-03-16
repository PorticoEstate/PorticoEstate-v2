# Api

New Api based on [Slim framework](https://www.slimframework.com/)

## Instructions to run the code

Follow these steps to get started:

1. **Clone the code**
Use your preferred method to clone the repository to your local machine.

2. **Configure the database**
Update the database configuration settings as per your environment.

3. **Build the image**

Set the `INSTALL_XDEBUG` environment variable and build your Docker image:

```bash
export INSTALL_XDEBUG=true
export INSTALL_MSSQL=true
docker compose build
```

4. **Start the server**

Launch your containers in detached mode:

```bash
docker compose up -d
```

5. **Install required 3rd-party libraries**

First-time: Execute the following command to install dependencies inside the `portico_api` container:

```bash
docker exec -it -w /var/www/html portico_api composer update
```

6. **Access the API**
The API will be available at [http://localhost:8088/](http://localhost:8088/)

**Note:** Ensure the `xdebug.client_port` is set to `9003`.


## A bit about the principles of the new API and how it is combined with existing code

All endpoints must be defined as a "route" and method (post, get, put, delete).

To handle existing code, a generic route has been created that handles everything as before:

```php
$app->get('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->get('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
$app->post('/index.php', StartPoint::class . ':run')->add(new SessionsMiddleware($app->getContainer()));
```

Example:
http://localhost/?menuaction=booking.uiapplication.index

It will first check for valid login and permissions, and then proceed to StartPoint::run().
This method has the same function as the old index.php in the original setup.

Not many new routes have been created, but there are principally three layers of security:

- Direct lookup without check/login for public data
- Lookup via a valid session
- Lookup via a valid session and assigned permission per entity/area/register

Example of public data returning JSON data:

    http://localhost/bookingfrontend/searchdataall
    http://localhost/bookingfrontend/lang

Example of data requiring login:

    http://localhost/booking/users
    http://localhost/booking/users/1

Login to API:

    http://localhost/login

Keep session "alive":

    http://localhost/refreshsession

Login to the full system as before:

    http://localhost/login_ui

Login to setup (rebuilt but looks the same as before):

    http://localhost/setup
    http://localhost/setup/
    (Two different ones, maybe not quite finished...)

In general, all routes are registered from a common method that traverses all ```src/modules/<module>/routes/Routes.php```.

## To update swagger documentation
```bash
docker exec -it -w /var/www/html portico_api php generate-swagger.php
```
Swagger documentation will be available at http://localhost/swagger
Note: You need a valid session to access the Swagger documentation.
