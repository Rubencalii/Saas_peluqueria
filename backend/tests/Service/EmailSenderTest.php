<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Email\EmailSender;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Canal de email. En tests `MAILER_DSN=null://null` → desactivado: el envío se
 * registra en el log y se considera correcto (modo local), como WhatsApp.
 */
final class EmailSenderTest extends KernelTestCase
{
    private EmailSender $email;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EmailSender $svc */
        $svc = static::getContainer()->get(EmailSender::class);
        $this->email = $svc;
    }

    public function testDesactivadoConDsnNull(): void
    {
        self::assertFalse($this->email->isEnabled());
    }

    public function testEnvioEnModoLocalSeConsideraCorrecto(): void
    {
        self::assertTrue($this->email->send('cliente@test.es', 'Asunto', 'Cuerpo'));
    }

    public function testDestinatarioVacioFalla(): void
    {
        self::assertFalse($this->email->send('', 'Asunto', 'Cuerpo'));
    }
}
