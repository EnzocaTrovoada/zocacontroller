<?php
/* Quem abre o endereço da API no navegador cai no site, e não na página
   padrão da hospedagem — ela não tem nada pra ninguém e ainda anuncia onde o
   servidor está hospedado. */
header('Location: https://mods.zocahop.com/', true, 302);
exit;
