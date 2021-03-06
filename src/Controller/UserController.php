<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Form\UserPasswordType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\FormError;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Entity;

#[Route('/user')]
class UserController extends AbstractController
{
    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(UserPasswordHasherInterface $passwordHasher, Request $request, User $user, UserRepository $userRepository): Response
    {
        $toastMessage = $request->get('toastMessage')? $request->get('toastMessage'): false;
        $toastType = $request->get('toastType')? $request->get('toastType'): '';
        $updateMessage = null;
        $userForm = $this->createForm(UserType::class, $user);
        $userPasswordForm = $this->createForm(UserPasswordType::class, $user);

        $userForm->handleRequest($request);
        $userPasswordForm->handleRequest($request);

        if ($userForm->isSubmitted() && $userForm->isValid()) {
            $userRepository->add($user);
            $toastMessage = "Profile successfully updated.";
            $toastType = 'success';
        } else if ($userPasswordForm->isSubmitted() && $userPasswordForm->isValid()) {
            $existingUser = $userRepository->loadUserByIdentifier($user->getUsername());

            if($existingUser != null && $existingUser->getId() != $user->getId()) {
                $toastMessage = "Username is already in use.";
                $toastType = 'warning';
                $userForm->addError(new FormError('Username is already in use.'));
            } else {
                $plaintextPassword = $user->getPassword1();

                // hash the password (based on the security.yaml config for the $user class)
                $hashedPassword = $passwordHasher->hashPassword(
                    $user,
                    $plaintextPassword
                );
                $user->setPassword($hashedPassword);

                $userRepository->add($user);
                $toastMessage = "Password successfully changed.";
                $toastType = 'success';
            }
        }

        return $this->renderForm('user/edit.html.twig', [
            'user' => $user,
            'userForm' => $userForm,
            'userPasswordForm' => $userPasswordForm,
            'updateMessage' => $updateMessage,
            'toastMessage' => $toastMessage,
            'toastType' => $toastType
        ]);
    }

    #[Route('/{email}/view', name: 'app_user_search', methods: ['GET'])]
    #[Entity('user', expr: 'repository.loadUserByEmail(email)')]
    public function view(string $email, Request $request, UserRepository $userRepository, User $user = null): Response
    {
        return $this->renderForm('user/view.html.twig', [
            'user' => $user
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, UserRepository $userRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $userRepository->remove($user);
        }

        return $this->redirectToRoute('app_home', [], Response::HTTP_SEE_OTHER);
    }
}
