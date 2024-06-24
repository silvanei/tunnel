------
# Tunnel

Provide a server and a client to expose your local host to the Internet.

> **Requires [Docker](https://www.docker.com/)**

🏗️ To build the development docker image:
```bash
make
```

📦 To install the composer dependencies, run the command below:
```bash
make install
```

👨‍💻 To start the application, run the command below:
```bash
make serve
```

📦 To connect on PHP container, run the command below:
```bash
make sh
```

🧹 Keep a modern codebase with **PHP Linter**:
```bash
make phpcs
```

⚗️ Run static analysis using **PHPStan**:
```bash
make phpstan
```

✅ Run unit tests with **PHPUnit**
```bash
make test
```
