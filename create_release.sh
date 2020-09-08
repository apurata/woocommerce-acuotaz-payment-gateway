#!/bin/bash

# This name *must* match with the git folder
PLUGIN_NAME=woocommerce-apurata-payment-gateway

(
	rm -rf ${PLUGIN_NAME}
	mkdir ${PLUGIN_NAME}

	cp LICENSE ${PLUGIN_NAME}
	cp readme.txt ${PLUGIN_NAME}
	cp *.php ${PLUGIN_NAME}

	zip -r ${PLUGIN_NAME}.zip ${PLUGIN_NAME}/

	rm -rf ${PLUGIN_NAME}
)