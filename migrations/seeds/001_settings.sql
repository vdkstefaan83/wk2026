-- Default settings
INSERT INTO settings (`key`, `value`) VALUES
('auth_provider',       'db'),
('registration_open',   '1'),
('predictions_open',    '1'),
('predictions_deadline','2026-06-11 17:00:00'),
('payment_amount',      '10.00'),
('payment_currency',    'EUR'),
('payment_recipient',   'Jonah'),
('payment_iban',        ''),
('payment_instructions','Gelieve het bedrag in cash aan Jonah te overhandigen.'),
('admin_mail_to',       'wk2026@psb.ugent.be')
;

-- Default email templates (Quill-editable)
INSERT INTO email_templates (`key`, subject, body_html) VALUES
('submission_user',
 'Jouw WK2026 voorspelling is ontvangen',
 '<p>Beste {{user_name}},</p><p>Bedankt voor het indienen van je WK2026 voorspelling <strong>"{{form_label}}"</strong>. In bijlage vind je een PDF met je definitieve voorspelling.</p><p><strong>Betaling:</strong> Gelieve <strong>{{payment_amount}} {{payment_currency}}</strong> aan <strong>{{payment_recipient}}</strong> te bezorgen om je deelname te bevestigen.</p><p>{{payment_instructions}}</p><p>Succes en moge de beste winnen!<br/>– WK2026 Pool</p>'),
('submission_admin',
 'Nieuwe WK2026 voorspelling: {{user_name}} – {{form_label}}',
 '<p>Een nieuwe voorspelling is ingediend.</p><ul><li><strong>Gebruiker:</strong> {{user_name}} ({{user_email}})</li><li><strong>Formulier:</strong> {{form_label}}</li><li><strong>Tijdstip:</strong> {{submitted_at}}</li></ul><p>De PDF zit in bijlage.</p>')
;
