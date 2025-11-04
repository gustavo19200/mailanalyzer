<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.

https://www.araymond.com/
-------------------------------------------------------------------------

LICENSE

This file is part of MailAnalyzer plugin for GLPI.

This file is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see <http://www.gnu.org/licenses/>.
--------------------------------------------------------------------------
 */

$LANG['mailanalyzer']['title'] = 'Mail Analyzer';
$LANG['mailanalyzer']['Mail Analyzer setup'] = 'Configuração do Mail Analyzer';
$LANG['mailanalyzer']['MailAnalyzer'] = 'MailAnalyzer';
$LANG['mailanalyzer']['Use of Thread index'] = 'Usar índice de Thread';
$LANG['mailanalyzer']['Block chain emails'] = 'Bloquear emails da cadeia';
$LANG['mailanalyzer']['When enabled, only the first email in a conversation will create a ticket. All subsequent emails in the same chain (replies, CC responses, etc.) will be blocked and moved to the refused folder.'] = 'Quando ativado, apenas o primeiro email de uma conversa criará um chamado. Todos os emails subsequentes da mesma cadeia (respostas, respostas com CC, etc.) serão bloqueados e movidos para a pasta de recusados.';

return $LANG;
