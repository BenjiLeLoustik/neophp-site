<?php

declare(strict_types=1);

namespace App\Controller;

use NeoPHP\Component\Controller\Contract\AbstractController;
use NeoPHP\Component\Http\Response\Response;
use NeoPHP\Component\Routing\Attribute\Route;
use NeoPHP\Package\Security\Exception\SecurityException;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirect('/');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $this->getLastUsername(),
            'error' => $this->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): Response
    {
        throw new SecurityException('This route is handled by the "logout" option of the firewall in config/packages/security.yaml.');
    }
}
