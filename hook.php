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
/**
 * Summary of plugin_mailanalyzer_install
 * @return boolean
 */
function plugin_mailanalyzer_install() {
   global $DB;

   if (!$DB->tableExists("glpi_plugin_mailanalyzer_message_id")) {
         $query = "CREATE TABLE `glpi_plugin_mailanalyzer_message_id` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` VARCHAR(255) NOT NULL DEFAULT '0',
            `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0',
            `mailcollectors_id` int UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`id`),
            UNIQUE INDEX `message_id` (`message_id`,`mailcollectors_id`),
            INDEX `tickets_id` (`tickets_id`)
         )
         COLLATE='utf8mb4_unicode_ci'
         ENGINE=innoDB;
         ";

         $DB->query($query) or die("error creating glpi_plugin_mailanalyzer_message_id " . $DB->error());
   } else {
      if (count($DB->listTables('glpi_plugin_mailanalyzer_message_id', ['engine' => 'MyIsam'])) > 0) {
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ENGINE = InnoDB";
         $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());
      }
   }
   if ($DB->fieldExists("glpi_plugin_mailanalyzer_message_id","mailgate_id"))
   {
      //STEP - UPDATE MAILGATE_ID INTO MAILCOLLECTORS_ID
      $query = "ALTER TABLE `glpi_plugin_mailanalyzer_message_id`
                CHANGE COLUMN `mailgate_id` `mailcollectors_id` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `message_id`,
                DROP INDEX `message_id`,
                ADD UNIQUE INDEX `message_id` (`message_id`, `mailcollectors_id`) USING BTREE;";
      $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());
   }
   if (!$DB->fieldExists("glpi_plugin_mailanalyzer_message_id","mailcollectors_id"))
   {
      //STEP - ADD mailcollectors_id
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ADD COLUMN `mailcollectors_id` int UNSIGNED NOT NULL DEFAULT 0 AFTER `message_id`";
         $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());

      //STEP - REMOVE UNICITY CONSTRAINT
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id DROP INDEX `message_id`";
         $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());
      //STEP - ADD NEW UNICITY CONSTRAINT
         $query = "ALTER TABLE glpi_plugin_mailanalyzer_message_id ADD UNIQUE KEY `message_id` (`message_id`,`mailcollectors_id`);";
         $DB->query($query) or die("error updating ENGINE in glpi_plugin_mailanalyzer_message_id " . $DB->error());
   }

   if (!$DB->fieldExists('glpi_plugin_mailanalyzer_message_id', 'tickets_id')) {
      // then we must change the name and the length of id and ticket_id to 11
      $query = "ALTER TABLE `glpi_plugin_mailanalyzer_message_id`
                  CHANGE COLUMN `id` `id` INT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
                  CHANGE COLUMN `ticket_id` `tickets_id` INT UNSIGNED NOT NULL DEFAULT '0' AFTER `message_id`,
                  DROP INDEX `ticket_id`,
                  ADD INDEX `ticket_id` (`tickets_id`);";
      $DB->query($query) or die('Cannot alter glpi_plugin_mailanalyzer_message_id table! ' .  $DB->error());
   }

   return true;
}


/**
 * Summary of plugin_mailanalyzer_uninstall
 * @return boolean
 */
function plugin_mailanalyzer_uninstall() {

   // nothing to uninstall
   // do not delete table

   return true;
}


/**
 * Summary of PluginMailAnalyzer
 */
class PluginMailAnalyzer {

   /**
    * Create default mailgate
    * @param int $mailcollectors_id is the id of the mail collector in GLPI DB
    * @return bool|MailCollector
   */
   static function openMailgate($mailcollectors_id) : PluginMailanalyzerMailCollector {

      $mailgate = new PluginMailanalyzerMailCollector();
      $mailgate->getFromDB($mailcollectors_id);
      $mailgate->uid = -1;
      $mailgate->connect();

      return $mailgate;
   }


   /**
   * Summary of plugin_pre_item_add_mailanalyzer
   * @param mixed $parm
   * @return void
   */
   public static function plugin_pre_item_add_mailanalyzer($parm) {
      global $DB, $mailgate;

      $mailgateId = $parm->input['_mailgate'] ?? false;
      if ($mailgateId) {
         // this ticket have been created via email receiver.
         // Analyzes emails to establish conversation

         // Get configuration
         $config = Config::getConfigurationValues('plugin:mailanalyzer');
         $use_threadindex = isset($config['use_threadindex']) && $config['use_threadindex'];
         $block_chain_emails = isset($config['block_chain_emails']) && $config['block_chain_emails'];

         if (isset($mailgate)) {
            // mailgate has been open by web page call, then use it
            $local_mailgate = $mailgate;
            // if use of threadindex is true then must open a new mailgate
            // to be able to get the threadindex of the email
            if ($use_threadindex) {
                $local_mailgate = PluginMailAnalyzer::openMailgate($mailgateId);
            }
         } else {
            // mailgate is not open. Called by cron
            // then locally create a mailgate
            $local_mailgate = PluginMailAnalyzer::openMailgate($mailgateId);
            if ($local_mailgate === false) {
               // can't connect to the mail server, then cancel ticket creation
               $parm->input = false;// []; // empty array...
               return;
            }
         }

        if ($use_threadindex) {
            $local_message = $local_mailgate->getMessage($parm->input['_uid']);
            $threadindex   = $local_mailgate->getThreadIndex($local_message);
            if ($threadindex) {
                // add threadindex to the '_head' of the input
                $parm->input['_head']['threadindex'] = $threadindex;
            }
        }


         // we must check if this email has not been received yet!
         // test if 'message-id' is in the DB
         $messageId = html_entity_decode($parm->input['_head']['message_id']);
         $uid = $parm->input['_uid'];
         $res = $DB->request(
            'glpi_plugin_mailanalyzer_message_id',
            [
            'AND' =>
               [
               'tickets_id'        => ['!=', 0],
               'message_id'        => $messageId,
               'mailcollectors_id' => $mailgateId
               ]
            ]
         );
         if ($row = $res->current()) {
            // email already received
            // must prevent ticket creation
            $parm->input = false; //[ ];

            // as Ticket creation is cancelled, then email is not deleted from mailbox
            // then we need to set deletion flag to true to this email from mailbox folder
            $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER); // NOK Folder

            return;
         }

         // search for 'Thread-Index' and 'References'
         $messages_id = self::getMailReferences(
             $parm->input['_head']['threadindex'] ?? '',
             html_entity_decode($parm->input['_head']['references'] ?? '')
             );

         if (count($messages_id) > 0) {
            $res = $DB->request(
               'glpi_plugin_mailanalyzer_message_id',
               ['AND' =>
                  [
                  'tickets_id'        => ['!=',0],
                  'message_id'        => $messages_id,
                  'mailcollectors_id' => $mailgateId
                  ],
                  'ORDER' => 'tickets_id DESC'
               ]
            );
            if ($row = $res->current()) {
               
               // *** LÓGICA CRÍTICA: Distinguir entre resposta legítima e cadeia externa ***
               $is_legitimate_reply = self::isLegitimateReplyToGlpi($parm->input, $row['tickets_id'], $mailgateId);
               
               // Se o bloqueio está ativo E não é resposta legítima ao GLPI, bloquear
               if ($block_chain_emails && !$is_legitimate_reply) {
                  // Este email faz parte da cadeia externa (CC entre usuários)
                  // Bloquear este email - não criar ticket nem followup
                  
                  Toolbox::logInFile('mailanalyzer', sprintf(
                     "Email BLOQUEADO (cadeia externa CC) - Message-ID: %s, Ticket: %s\n",
                     $messageId,
                     $row['tickets_id']
                  ));

                  // Cancelar criação do ticket
                  $parm->input = false;

                  // Mover email para pasta de recusados
                  $local_mailgate->deleteMails($uid, MailCollector::REFUSED_FOLDER);

                  return;
               }
               
               // Email legítimo (resposta ao GLPI) - continuar normalmente
               if ($block_chain_emails && $is_legitimate_reply) {
                  Toolbox::logInFile('mailanalyzer', sprintf(
                     "Email PERMITIDO (resposta ao GLPI) - Message-ID: %s, Ticket: %s\n",
                     $messageId,
                     $row['tickets_id']
                  ));
               }

               // *** LÓGICA ORIGINAL: Adicionar como followup ***
               // TicketFollowup creation only if ticket status is not closed
               $locTicket = new Ticket();
               $locTicket->getFromDB((integer)$row['tickets_id']);
               if ($locTicket->fields['status'] != CommonITILObject::CLOSED) {
                  $ticketfollowup = new ITILFollowup();
                  $input = $parm->input;
                  $input['items_id']   = $row['tickets_id'];
                  $input['users_id']   = $parm->input['_users_id_requester'];
                  $input['add_reopen'] = 1;
                  $input['itemtype']   = 'Ticket';

                  unset($input['urgency']);
                  unset($input['entities_id']);
                  unset($input['_ruleid']);

                  $ticketfollowup->add($input);

                  // add message id to DB in case of another email will use it
                  $DB->insert(
                     'glpi_plugin_mailanalyzer_message_id',
                     [
                        'message_id'        => $messageId,
                        'tickets_id'        => $input['items_id'],
                        'mailcollectors_id' => $mailgateId
                     ]
                  );

                  // prevent Ticket creation. Unfortunately it will return an error to receiver when started manually from web page
                  $parm->input = false; // []; // empty array...

                  // as Ticket creation is cancelled, then email is not deleted from mailbox
                  // then we need to set deletion flag to true to this email from mailbox folder
                  $local_mailgate->deleteMails($uid, MailCollector::ACCEPTED_FOLDER); // OK folder

                  return;

               } else {
                  // ticket creation, but linked to the closed one...
                  $parm->input['_link'] = ['link' => '1', 'tickets_id_1' => '0', 'tickets_id_2' => $row['tickets_id']];
               }
            }
         }

         // can't find ref into DB, then this is a new ticket, in this case insert refs and message_id into DB
         $messages_id[] = $messageId;

         // this is a new ticket
         // then add references and message_id to DB
         foreach ($messages_id as $ref) {
            $res = $DB->request('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref, 'mailcollectors_id' => $mailgateId]);
            if (count($res) <= 0) {
               $DB->insert('glpi_plugin_mailanalyzer_message_id', ['message_id' => $ref, 'mailcollectors_id' => $mailgateId]);
            }
         }
      }
   }


   /**
    * Verificar se o email é uma resposta legítima às notificações do GLPI
    * @param array $input Email input com headers
    * @param int $ticket_id ID do ticket relacionado
    * @param int $mailgateId ID do coletor de email
    * @return bool True se for resposta legítima ao GLPI, False se for cadeia externa
    */
   private static function isLegitimateReplyToGlpi($input, $ticket_id, $mailgateId) {
      global $DB;
      
      // ===================================================================
      // MÉTODO 1: Verificar se GLPI já identificou o ticket via regras
      // ===================================================================
      // Quando o usuário responde à notificação do GLPI, as regras do GLPI
      // normalmente identificam o ticket_id e o incluem no input
      if (isset($input['_ticket_id']) && $input['_ticket_id'] == $ticket_id) {
         return true;
      }
      
      // ===================================================================
      // MÉTODO 2: Verificar padrão no subject [GLPI #XXXX]
      // ===================================================================
      // GLPI adiciona [GLPI #1234] ou similar no subject das notificações
      if (isset($input['_head']['subject'])) {
         $subject = $input['_head']['subject'];
         // Padrões comuns: [GLPI #1234], [GLPI-#1234], (GLPI #1234), etc.
         if (preg_match('/[\[\(].*?#\s*(\d+)\s*[\]\)]/', $subject, $matches)) {
            $ticket_id_from_subject = $matches[1];
            if ($ticket_id_from_subject == $ticket_id) {
               return true;
            }
         }
      }
      
      // ===================================================================
      // MÉTODO 3: Verificar In-Reply-To (método mais confiável)
      // ===================================================================
      // Quando o usuário responde à notificação do GLPI, o header In-Reply-To
      // contém o Message-ID da notificação enviada pelo GLPI.
      // Emails de notificação do GLPI NÃO estão na tabela message_id
      // (só emails recebidos estão lá).
      // Portanto, se In-Reply-To NÃO está na tabela = resposta ao GLPI
      if (isset($input['_head']['in-reply-to'])) {
         $in_reply_to = html_entity_decode($input['_head']['in-reply-to']);
         // Limpar o Message-ID (remover < >)
         $in_reply_to = trim($in_reply_to, '<> ');
         
         // Verificar se está na tabela
         $check_res = $DB->request(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'AND' => [
                  'message_id' => $in_reply_to,
                  'mailcollectors_id' => $mailgateId
               ]
            ]
         );
         
         // Se In-Reply-To NÃO está na tabela = É resposta à notificação do GLPI
         if (count($check_res) == 0) {
            return true;
         }
      }
      
      // ===================================================================
      // MÉTODO 4: Verificar References (segunda opção)
      // ===================================================================
      // Similar ao In-Reply-To, mas verifica o primeiro Message-ID
      // da lista de references (o mais recente geralmente é o último)
      if (isset($input['_head']['references'])) {
         $references = html_entity_decode($input['_head']['references']);
         // Extrair todos os Message-IDs
         if (preg_match_all('/<([^>]+)>/', $references, $matches)) {
            // Pegar o último (mais recente)
            $last_ref = end($matches[1]);
            
            // Verificar se está na tabela
            $check_res = $DB->request(
               'glpi_plugin_mailanalyzer_message_id',
               [
                  'AND' => [
                     'message_id' => $last_ref,
                     'mailcollectors_id' => $mailgateId
                  ]
               ]
            );
            
            // Se o último reference NÃO está na tabela = resposta ao GLPI
            if (count($check_res) == 0) {
               return true;
            }
         }
      }
      
      // ===================================================================
      // NENHUM método identificou como resposta legítima
      // Portanto, é parte da cadeia externa (CC entre usuários)
      // ===================================================================
      return false;
   }


    /**
     * Summary of plugin_item_add_mailanalyzer
     * @param mixed $parm
     */
   public static function plugin_item_add_mailanalyzer($parm) {
      global $DB;
      if (isset($parm->input['_mailgate'])) {
         // this ticket have been created via email receiver.
         // update the ticket ID for the message_id only for newly created tickets (tickets_id == 0)

	      // Are 'Thread-Index' or 'Refrences' present?
         $messages_id = self::getMailReferences(
             $parm->input['_head']['threadindex'] ?? '',
             html_entity_decode($parm->input['_head']['references'] ?? '')
             );
         $messages_id[] = html_entity_decode($parm->input['_head']['message_id']);

         $DB->update(
            'glpi_plugin_mailanalyzer_message_id',
            [
               'tickets_id' => $parm->fields['id']
            ],
            [
               'WHERE' =>
                  [
                     'AND' =>
                        [
                           'tickets_id'  => 0,
                           'message_id' => $messages_id
                        ]
                  ]
            ]
         );
      }
   }


   /**
    * Summary of getMailReferences
    * @param string $threadindex 
    * @param string $references 
    * @return string[]
    */
   private static function getMailReferences(string $threadindex, string $references) {

      $messages_id = []; // by default

      if (!empty($threadindex)) {
          $messages_id[] = $threadindex;
      }

      // search for 'References'
      if (!empty($references)) {
         // we may have a forwarded email that looks like reply-to
         if (preg_match_all('/<.*?>/', $references, $matches)) {
            $messages_id = array_merge($messages_id, $matches[0]);
         }
      }

      // clean $messages_id array
      return array_filter($messages_id, function($val) {return $val != trim('', '< >');});
   }


   /**
    * Summary of plugin_item_purge_mailanalyzer
    * @param mixed $item
    */
   static function plugin_item_purge_mailanalyzer($item) {
      global $DB;
      // the ticket is purged, then we are going to purge the matching rows in glpi_plugin_mailanalyzer_message_id table
      // DELETE FROM glpi_plugin
      $DB->delete('glpi_plugin_mailanalyzer_message_id', ['tickets_id' => $item->getID()]);
   }
}
