## Requirements
- Docker and Docker Compose
- Expose token, follow instruction here: https://expose.beyondco.de/docs/introduction to get a token

## Installation
1. Clone the repository:
```
git clone https://github.com/MultiSafepay/shopware6.git
``` 

2. Copy the example env file and make the required configuration changes in the .env file:
```
cp .env.example .env
```
- **EXPOSE_HOST** can be set to the expose server to connect to
- **APP_SUBDOMAIN** replace the `-xx` in `shopware6-dev-xx` with a number for example `shopware6-dev-05`
- **EXPOSE_TOKEN** must be filled in

3. Start the Docker containers
```
docker-compose up
```
The above command will start the container and show you some logs, this helps when you start for the first time,
so you'll see any error message that might happen. You can shut down the containers by opening another terminal,
access this project directory and execute `docker-compose down`. The next time you want to start the containers
you can execute `docker-compose up -d`.

4. Update the Shopware domain for the sales channel 
```
make update-host
```

5. Install and activate the MultiSafepay plugin
```
make install
```
