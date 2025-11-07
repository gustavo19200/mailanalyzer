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

                  // Enviar notificação se configurado
                  self::sendRefusedEmailNotification($parm->input, $row['tickets_id'], $config);

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
    * Enviar notificação quando um email é recusado
    * @param array $input Email input
    * @param int $ticket_id ID do ticket relacionado
    * @param array $config Configuração do plugin
    * @return void
    */
   private static function sendRefusedEmailNotification($input, $ticket_id, $config) {
      global $CFG_GLPI;
      
      // Verificar se há template configurado
      if (!isset($config['refused_notification_template']) || $config['refused_notification_template'] == 0) {
         Toolbox::logInFile('mailanalyzer', "Notificação não enviada: template não configurado\n");
         return;
      }

      try {
         // Carregar o ticket relacionado
         $ticket = new Ticket();
         if (!$ticket->getFromDB($ticket_id)) {
            Toolbox::logInFile('mailanalyzer', "Erro: Ticket #{$ticket_id} não encontrado para enviar notificação de recusa\n");
            return;
         }

         // Buscar email do requisitante
         $requester_email = '';
         $requester_name = '';
         
         // Tentar pegar do usuário requisitante do ticket
         if (isset($input['_users_id_requester'])) {
            $user = new User();
            if ($user->getFromDB($input['_users_id_requester'])) {
               $requester_email = $user->getDefaultEmail();
               $requester_name = $user->getFriendlyName();
            }
         }

         // Se não conseguiu, tentar do header do email
         if (empty($requester_email) && isset($input['_head']['from'])) {
            $from_header = $input['_head']['from'];
            // Extrair email do formato "Nome <email@example.com>"
            if (preg_match('/<(.+?)>/', $from_header, $matches)) {
               $requester_email = trim($matches[1]);
               // Extrair nome
               $requester_name = trim(preg_replace('/<.+?>/', '', $from_header));
            } else {
               $requester_email = trim($from_header);
               $requester_name = $requester_email;
            }
         }

         if (empty($requester_email)) {
            Toolbox::logInFile('mailanalyzer', sprintf(
               "❌ Notificação não enviada: email do remetente não encontrado (Ticket: #%s)\n",
               $ticket_id
            ));
            return;
         }

         // Carregar o template
         $template = new NotificationTemplate();
         if (!$template->getFromDB($config['refused_notification_template'])) {
            Toolbox::logInFile('mailanalyzer', sprintf(
               "❌ Erro: Template #%s não encontrado\n",
               $config['refused_notification_template']
            ));
            return;
         }

         // Preparar dados para substituição no template
         $data = [
            'ticket' => [
               'id' => $ticket_id,
               'title' => $ticket->fields['name'],
               'content' => $ticket->fields['content'],
               'url' => $CFG_GLPI['url_base'] . '/front/ticket.form.php?id=' . $ticket_id,
               'entity' => Dropdown::getDropdownName('glpi_entities', $ticket->fields['entities_id']),
               'status' => Ticket::getStatus($ticket->fields['status']),
               'priority' => Ticket::getPriorityName($ticket->fields['priority']),
               'creationdate' => Html::convDateTime($ticket->fields['date'])
            ],
            'refused_email_from' => $input['_head']['from'] ?? 'Desconhecido',
            'refused_email_subject' => $input['_head']['subject'] ?? 'Sem assunto',
            'refused_email_date' => $input['_head']['date'] ?? date('Y-m-d H:i:s'),
            'refused_reason' => 'Email faz parte de cadeia CC entre usuários',
            'tickets_id' => $ticket_id
         ];

         // Criar objeto de notificação
         $mailing = new NotificationMailing();
         $options = [
            'entities_id' => $ticket->fields['entities_id'],
            'notificationtemplates_id' => $config['refused_notification_template'],
            'items_id' => $ticket_id,
            'itemtype' => 'Ticket'
         ];

         // Buscar tradução do template no idioma do usuário
         $translation = new NotificationTemplateTranslation();
         $translations = $translation->find([
            'notificationtemplates_id' => $config['refused_notification_template'],
            'language' => $_SESSION['glpilanguage'] ?? ''
         ]);

         // Se não encontrou tradução no idioma do usuário, buscar qualquer tradução
         if (count($translations) == 0) {
            $translations = $translation->find([
               'notificationtemplates_id' => $config['refused_notification_template']
            ], ['id ASC'], 1);
         }

         if (count($translations) > 0) {
            $translation_data = reset($translations);
            
            // Processar subject e body substituindo as variáveis
            $subject = $translation_data['subject'];
            $body = $translation_data['content_html'] ?: $translation_data['content_text'];
            
            // Substituir variáveis ##variable##
            foreach ($data as $key => $value) {
               if (is_array($value)) {
                  foreach ($value as $subkey => $subvalue) {
                     $subject = str_replace("##${key}.${subkey}##", $subvalue, $subject);
                     $body = str_replace("##${key}.${subkey}##", $subvalue, $body);
                  }
               } else {
                  $subject = str_replace("##${key}##", $value, $subject);
                  $body = str_replace("##${key}##", $value, $body);
               }
            }

            // Enviar email diretamente usando GLPIMailer
            $mail = new GLPIMailer();
            $mail->AddAddress($requester_email, $requester_name);
            $mail->Subject = $subject;
            
            if (!empty($translation_data['content_html'])) {
               $mail->isHTML(true);
               $mail->Body = $body;
            } else {
               $mail->isHTML(false);
               $mail->Body = $body;
            }

            // Enviar
            if ($mail->send()) {
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "✅ Notificação de recusa enviada com sucesso!\n" .
                  "   Para: %s (%s)\n" .
                  "   Ticket: #%s\n" .
                  "   Template: #%s\n" .
                  "   Assunto: %s\n",
                  $requester_email,
                  $requester_name,
                  $ticket_id,
                  $config['refused_notification_template'],
                  $subject
               ));
               return true;
            } else {
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "❌ Erro ao enviar email: %s\n" .
                  "   Para: %s\n" .
                  "   Ticket: #%s\n",
                  $mail->ErrorInfo,
                  $requester_email,
                  $ticket_id
               ));
               return false;
            }
         } else {
            Toolbox::logInFile('mailanalyzer', sprintf(
               "❌ Erro: Nenhuma tradução encontrada para o template #%s\n",
               $config['refused_notification_template']
            ));
            return false;
         }

      } catch (Exception $e) {
         Toolbox::logInFile('mailanalyzer', sprintf(
            "❌ EXCEÇÃO ao enviar notificação de recusa:\n" .
            "   Erro: %s\n" .
            "   Arquivo: %s\n" .
            "   Linha: %s\n" .
            "   Ticket: #%s\n",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $ticket_id
         ));
         return false;
      }
   }


   /**
    * Verificar se o email é uma resposta legítima às notificações do GLPI
    * 
    * Cenários:
    * 1. Resposta direta à notificação do GLPI: PERMITIR (criar followup)
    * 2. Resposta entre usuários (cadeia CC): BLOQUEAR
    * 3. Primeiro email da thread: PERMITIR (criar ticket)
    * 
    * @param array $input Email input com headers
    * @param int $ticket_id ID do ticket relacionado
    * @param int $mailgateId ID do coletor de email
    * @return bool True se for resposta legítima ao GLPI, False se for cadeia externa
    */
   private static function isLegitimateReplyToGlpi($input, $ticket_id, $mailgateId) {
      global $DB;
      
      $messageId = html_entity_decode($input['_head']['message_id']);
      
      // ===================================================================
      // MÉTODO 1: Verificar se References contém Message-ID do GLPI
      // ===================================================================
      // Se References contém um Message-ID começando com "GLPI_", 
      // é uma resposta legítima à notificação do GLPI
      
      if (isset($input['_head']['references'])) {
         $references = html_entity_decode($input['_head']['references']);
         
         // Procurar por Message-ID do GLPI no formato: <GLPI_...@...>
         if (preg_match('/<GLPI_[^>]+>/', $references)) {
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "✅ PERMITIDO: References contém Message-ID do GLPI (resposta à notificação)\n" .
                  "   Message-ID: %s\n" .
                  "   Ticket: %s\n" .
                  "   References: %s\n",
                  $messageId,
                  $ticket_id,
                  substr($references, 0, 200)
               ));
               return true; // PERMITIR - é resposta ao GLPI
         }
      }
      
      // ===================================================================
      // MÉTODO 2: Verificar In-Reply-To
      // ===================================================================
      // Se In-Reply-To aponta para um Message-ID do GLPI, PERMITIR
      // Se In-Reply-To ESTÁ na tabela (email recebido de usuário), BLOQUEAR
      
      if (isset($input['_head']['in-reply-to'])) {
         $in_reply_to = trim(html_entity_decode($input['_head']['in-reply-to']), '<> ');
         
         // Verificar se é resposta direta a uma notificação do GLPI
         if (preg_match('/^GLPI_/', $in_reply_to)) {
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "✅ PERMITIDO: In-Reply-To é Message-ID do GLPI (resposta direta à notificação)\n" .
                  "   Message-ID: %s\n" .
                  "   Ticket: %s\n" .
                  "   In-Reply-To: %s\n",
                  $messageId,
                  $ticket_id,
                  $in_reply_to
               ));
               return true; // PERMITIR - é resposta ao GLPI
         }
         
         // Verificar se este Message-ID está na tabela (email recebido de usuário)
         $check_res = $DB->request(
               'glpi_plugin_mailanalyzer_message_id',
               [
                  'AND' => [
                     'message_id' => $in_reply_to,
                     'mailcollectors_id' => $mailgateId
                  ]
               ]
         );
         
         if (count($check_res) > 0) {
               // In-Reply-To ESTÁ na tabela = resposta a email RECEBIDO = cadeia CC
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "❌ BLOQUEADO: In-Reply-To encontrado na tabela (resposta entre usuários - cadeia CC)\n" .
                  "   Message-ID: %s\n" .
                  "   Ticket: %s\n" .
                  "   In-Reply-To: %s\n",
                  $messageId,
                  $ticket_id,
                  $in_reply_to
               ));
               return false; // BLOQUEAR - é resposta entre usuários
         } else {
               // In-Reply-To NÃO está na tabela E não é Message-ID do GLPI
               // Pode ser uma resposta a outro email que foi deletado ou não processado
               // Por segurança, vamos considerar como legítimo
               Toolbox::logInFile('mailanalyzer', sprintf(
                  "✅ PERMITIDO: In-Reply-To não encontrado na tabela (resposta legítima)\n" .
                  "   Message-ID: %s\n" .
                  "   Ticket: %s\n" .
                  "   In-Reply-To: %s\n",
                  $messageId,
                  $ticket_id,
                  $in_reply_to
               ));
               return true; // PERMITIR
         }
      }
      
      // ===================================================================
      // MÉTODO 3 (FALLBACK): Verificar se é o primeiro email da thread
      // ===================================================================
      // Se não há In-Reply-To nem References com GLPI_, verificar se já 
      // existe algum email deste ticket na tabela
      
      $res = $DB->request(
         'glpi_plugin_mailanalyzer_message_id',
         [
               'AND' => [
                  'tickets_id' => $ticket_id,
                  'mailcollectors_id' => $mailgateId
               ]
         ]
      );
      
      if (count($res) > 0) {
         // Ticket já tem emails registrados = não é o primeiro = BLOQUEAR
         Toolbox::logInFile('mailanalyzer', sprintf(
               "❌ BLOQUEADO: Ticket já tem emails registrados e não há indicação de resposta ao GLPI\n" .
               "   Message-ID: %s\n" .
               "   Ticket: %s\n" .
               "   Emails existentes: %d\n",
               $messageId,
               $ticket_id,
               count($res)
         ));
         return false; // BLOQUEAR
      }
      
      // Se não há registros = é o primeiro email = PERMITIR
      Toolbox::logInFile('mailanalyzer', sprintf(
         "✅ PERMITIDO: Primeiro email da thread (GLPI incluído no meio da cadeia)\n" .
         "   Message-ID: %s\n" .
         "   Ticket: %s\n",
         $messageId,
         $ticket_id
      ));
      return true; // PERMITIR - é o primeiro email
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
