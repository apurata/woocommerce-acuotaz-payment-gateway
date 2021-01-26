#!/bin/bash

# This name *must* match with the git folder
PLUGIN_NAME=woocommerce-apurata-payment-gateway

(
	rm -rf ${PLUGIN_NAME}
	mkdir ${PLUGIN_NAME}

	cp LICENSE ${PLUGIN_NAME}
	cp readme.txt ${PLUGIN_NAME}
	cp ${PLUGIN_NAME}.php ${PLUGIN_NAME}
	cp -r includes ${PLUGIN_NAME}
	find ${PLUGIN_NAME} -type d -exec chmod 755 {} \;
	find ${PLUGIN_NAME} -type f -exec chmod 644 {} \;
	zip -r ${PLUGIN_NAME}.zip ${PLUGIN_NAME}/

	rm -rf ${PLUGIN_NAME}
)
