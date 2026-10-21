FROM public.ecr.aws/lambda/nodejs:22

RUN dnf install -y tar gzip && dnf clean all

COPY package.json package-lock.json ${LAMBDA_TASK_ROOT}/
COPY util/install-php-runtime.js ${LAMBDA_TASK_ROOT}/util/install-php-runtime.js
COPY vendor/php/ ${LAMBDA_TASK_ROOT}/vendor/php/
RUN npm ci --omit=optional --omit=dev

COPY api/ ${LAMBDA_TASK_ROOT}/api/
COPY util/ ${LAMBDA_TASK_ROOT}/util/
COPY wp/ ${LAMBDA_TASK_ROOT}/wp/
COPY test/installer.php ${LAMBDA_TASK_ROOT}/wp/
COPY test/minio-offload.php ${LAMBDA_TASK_ROOT}/wp/wp-content/mu-plugins/

CMD [ "api/index.handler" ]