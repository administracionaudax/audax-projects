#!/bin/bash
# esperar-puerto.sh PUERTO SEGUNDOS: espera a que 127.0.0.1:PUERTO acepte conexiones (ExecStartPre de las unidades audax-*).
p=$1; t=${2:-60}
for ((i=0; i<t; i+=2)); do
  (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null && exit 0
  sleep 2
done
echo "127.0.0.1:$p no responde tras ${t}s" >&2
exit 1
