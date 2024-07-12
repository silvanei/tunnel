ARTIFACT_REVISION=dev
PROJECT_NAME=tunnel
PROJECT_DIR=$(shell pwd)
USER_ID=$(shell id -u)
USER_GROUP=$(shell id -g)
ITERATIVE=-it
ifdef CI
	ITERATIVE=
endif
DOCKER_CONTAINER_RUN=docker container run \
	$(ITERATIVE) \
	--rm \
	--network host \
	-m 1024m \
	-u $(USER_ID):$(USER_GROUP) \
	-v $(PROJECT_DIR):/app/$(PROJECT_NAME) \
	-w /app/$(PROJECT_NAME) silvanei/$(PROJECT_NAME):$(ARTIFACT_REVISION)

.PHONY: default
default: image;

image:
	docker build -t silvanei/$(PROJECT_NAME):$(ARTIFACT_REVISION) .

install:
	$(DOCKER_CONTAINER_RUN) composer install

serve:
	docker compose --profile dev up

sh:
	$(DOCKER_CONTAINER_RUN) sh

test:
	$(DOCKER_CONTAINER_RUN) composer test

test-coverage:
	$(DOCKER_CONTAINER_RUN) composer test-coverage

test-infection:
	$(DOCKER_CONTAINER_RUN) composer test-infection

phpstan:
	$(DOCKER_CONTAINER_RUN) composer phpstan

phpcs:
	$(DOCKER_CONTAINER_RUN) composer phpcs

check:
	$(DOCKER_CONTAINER_RUN) composer check
