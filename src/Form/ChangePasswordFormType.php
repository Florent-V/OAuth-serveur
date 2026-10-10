<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'first_options' => ['label' => 'Nouveau mot de passe', 'attr' => ['autocomplete' => 'new-password', 'autofocus' => true]],
            'second_options' => ['label' => 'Confirmez le mot de passe', 'attr' => ['autocomplete' => 'new-password']],
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir un mot de passe.'),
                new Length(min: 10, max: 4096, minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'),
            ],
        ]);
    }
}
