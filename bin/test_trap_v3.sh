#!/bin/bash
snmptrap -v 3 -a SHA -A UserPassword -x AES -X EncryptionKey -l authPriv -u trapuser -e 0x8000000001020304 localhost 0 IF-MIB::linkUp
