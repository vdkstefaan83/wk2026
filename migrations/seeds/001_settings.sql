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
('payment_instructions','Please hand the payment to Jonah in cash.'),
('admin_mail_to',       'wk2026@psb.ugent.be'),
('tiebreaker_question', 'How many goals will be scored in the entire tournament?'),
('tiebreaker_correct_value','')
;

-- Default email templates (Quill-editable)
INSERT INTO email_templates (`key`, subject, body_html) VALUES
('submission_user',
 'Your World Cup 2026 prediction has been received',
 '<p>Hi {{user_name}},</p><p>Thanks for submitting your World Cup 2026 prediction <strong>"{{form_label}}"</strong>. Attached you''ll find a PDF with your final picks.</p><p><strong>Payment:</strong> Please pay <strong>{{payment_amount}} {{payment_currency}}</strong> to <strong>{{payment_recipient}}</strong> to confirm your entry.</p><p>{{payment_instructions}}</p><p>Good luck — may the best predictor win!<br/>– World Cup 2026 Pool</p>'),
('submission_admin',
 'New World Cup 2026 prediction: {{user_name}} – {{form_label}}',
 '<p>A new prediction has been submitted.</p><ul><li><strong>User:</strong> {{user_name}} ({{user_email}})</li><li><strong>Form:</strong> {{form_label}}</li><li><strong>Submitted at:</strong> {{submitted_at}}</li></ul><p>The PDF is attached.</p>')
;
