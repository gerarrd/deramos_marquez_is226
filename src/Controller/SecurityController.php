<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\UserRepository;
use App\Repository\WebConfigRepository;
use App\Entity\User;
use App\Form\UserSignupType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Form\FormError;

class SecurityController extends AbstractController
{
    const VERIFICATION_EMAIL_NOT_YET_SENT = 'VERIFICATION_EMAIL_NOT_YET_SENT';
    const VERIFICATION_EMAIL_SENT = 'VERIFICATION_EMAIL_SENT';
    const VERIFICATION_EMAIL_VALIDATED = 'VERIFICATION_EMAIL_VALIDATED';
    const VERIFICATION_EMAIL_FAILED = 'VERIFICATION_EMAIL_FAILED';

    #[Route('/login', name: 'app_login')]
    public function login(UserInterface $user = null, AuthenticationUtils $authenticationUtils): Response
    {
        if($user != null) {
            return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
        }

        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();
         
        return $this->renderForm('security/login.html.twig', [
            'last_username' => $lastUsername,
            'error' => $error
        ]);
    }

    #[Route('/signup', name: 'app_signup')]
    public function signup(WebConfigRepository $webConfigRepository, UserInterface $user = null, UserPasswordHasherInterface $passwordHasher, 
        Request $request, VerifyEmailHelperInterface $verifyEmailHelper, UserRepository $userRepository, MailerInterface $mailer): Response
    {
        $webConfig = $webConfigRepository->findOneBy([]);
        $termsAndConditions = $webConfig != null && $webConfig->getTermsAndConditions() != null? $webConfig->getTermsAndConditions() : 'Sample Terms and Conditions.';

        if($user != null) {
            return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
        }

        $user = new User();
        $form = $this->createForm(UserSignupType::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $existingUser = $userRepository->loadUserByIdentifier($user->getEmail());

            if($existingUser != null) {
                $form
                    ->get('email')
                    ->addError(new FormError('Email is already in use.'));
            } else {
                $this->generateUserAndSendVerificationEmail($passwordHasher, $user, $verifyEmailHelper, $userRepository, $mailer);
                return $this->redirectToRoute('app_signup_success', [], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderForm('security/signup.html.twig', [
            'user' => $user,
            'form' => $form,
            'termsAndConditions' => $termsAndConditions
        ]);
    }

    private function generateUserAndSendVerificationEmail(UserPasswordHasherInterface $passwordHasher, User $user, VerifyEmailHelperInterface $verifyEmailHelper, UserRepository $userRepository, MailerInterface $mailer): void
    {
        $plaintextPassword = $user->getPassword1();
        $hashedPassword = $passwordHasher->hashPassword(
            $user,
            $plaintextPassword
        );
        $user->setPassword($hashedPassword);
        $user->setNickname(strtok($user->getEmail(), '@'));
        $userRepository->add($user);

        $signatureComponents = $verifyEmailHelper->generateSignature(
            'app_verify',
            $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()]
        );
        
        $email = (new TemplatedEmail())
            ->from('no-reply@mayutangba.me')
            ->to($user->getEmail())
            ->subject('Confirm Your Email and Get Started')
            ->htmlTemplate('email/verify-email.html.twig')
            ->context([
                'verification_url' => $signatureComponents->getSignedUrl()
            ]);

        $mailer->send($email);
        $user->setIsEmailVerificationSent(true);
        $userRepository->add($user);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void
    {
        // controller can be blank: it will never be called!
        throw new \Exception('Don\'t forget to activate logout in security.yaml');
    }

    #[Route('/verify', name: 'app_verify')]
    public function verify(UserInterface $user = null, Request $request, VerifyEmailHelperInterface $verifyEmailHelper, UserRepository $userRepository, LoggerInterface $logger): Response 
    {
        if($user && $user->isVerified()) {
            return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
        }

        $userId = $request->query->get('id');
        if($userId) {
            $user = $userRepository->find($userId);
            if (!$user) {
                throw $this->createNotFoundException();
            } else {
                try {
                    $verifyEmailHelper->validateEmailConfirmation(
                        $request->getUri(),
                        $user->getId(),
                        $user->getEmail(),
                    );

                    $user->setIsVerified(true);
                    $userRepository->add($user);
                    $verificationStatus = self::VERIFICATION_EMAIL_VALIDATED;
                    $proceedLocation = '/';
                } catch (\Exception $e) {
                    $verificationStatus = self::VERIFICATION_EMAIL_FAILED;
                    $error = $e->getReason();
                    $proceedLocation = '/verify-email';
                }
                
                return $this->render('security/verify.html.twig', [
                    'verification_status' => $verificationStatus,
                    'proceed_location' => $proceedLocation
                ]);
            }
        } else if($user) {
            $verificationStatus = $request->query->get('verification_sent') || $user->isEmailVerificationSent()? self::VERIFICATION_EMAIL_SENT: self::VERIFICATION_EMAIL_NOT_YET_SENT;
            $proceedLocation = $request->query->get('verification_sent') || $user->isEmailVerificationSent()? '/#': '/verify-email';
            return $this->render('security/verify.html.twig', [
                'verification_status' => $verificationStatus,
                'proceed_location' => $proceedLocation
            ]);
        } else {
            return $this->redirectToRoute('login', [], Response::HTTP_SEE_OTHER);
        }
    }

    #[Route('/verify-email', name: 'app_verify_email')]
    public function verifyEmail(UserInterface $user = null, VerifyEmailHelperInterface $verifyEmailHelper, MailerInterface $mailer, UserRepository $userRepository): Response
    {
        if(!$user->isEmailVerificationSent()) {
            $signatureComponents = $verifyEmailHelper->generateSignature(
                'app_verify',
                $user->getId(),
                $user->getEmail(),
                ['id' => $user->getId()]
            );
            
            $email = (new TemplatedEmail())
                ->from('no-reply@mayutangba.me')
                ->to($user->getEmail())
                ->subject('Confirm Your Email and Get Started')
                ->htmlTemplate('email/verify-email.html.twig')
                ->context([
                    'verification_url' => $signatureComponents->getSignedUrl()
                ]);
    
            $mailer->send($email);
            $user->setIsEmailVerificationSent(true);
            $userRepository->add($user);
        }

        return $this->redirectToRoute('app_verify', ['verification_sent' => true], Response::HTTP_SEE_OTHER);
    }

    #[Route('/reset-password', name: 'app_reset_password')]
    public function resetPassword()
    {
        return $this->render('security/reset-password.html.twig', [
        ]);
    }

    #[Route('/signup-success', name: 'app_signup_success')]
    public function signupSuccess()
    {
        return $this->render('security/signup-success.html.twig', []);
    }

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function forgotPassword(UserInterface $user = null)
    {
        if($user != null) {
            return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('security/forgot-password.html.twig', []);
    }
}