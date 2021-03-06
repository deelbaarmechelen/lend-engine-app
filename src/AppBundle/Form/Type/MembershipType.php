<?php
namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;

class MembershipType extends AbstractType
{
    protected $em;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $this->em = $options['em'];

        $builder->add('membershipType', EntityType::class, array(
            'class' => 'AppBundle:MembershipType',
            'choice_label' => 'fullName',
            'empty_data'  => '- Select -',
            'label' => 'Choose a membership type',
            'required' => true,
        ));

        $builder->add('price', CurrencyamountType::class, array(
            'label' => 'Membership cost',
            'required' => true,
            'attr' => [
                'class' => 'input-100'
            ]
        ));

        $activePaymentMethods = $this->em->getRepository("AppBundle:PaymentMethod")->findAllOrderedByName();
        $builder->add('paymentMethod', EntityType::class, array(
            'label' => 'Payment method',
            'class' => 'AppBundle:PaymentMethod',
            'choice_label' => 'name',
            'choices' => $activePaymentMethods,
            'required' => false,
            'mapped' => false,
            'attr' => [
                'class' => 'payment-method'
            ]
        ));

        $builder->add('paymentAmount', CurrencyamountType::class, array(
            'label' => 'Payment amount',
            'required' => false,
            'mapped' => false,
            'attr' => [
                'class' => 'payment-amount input-100'
            ]
        ));

        $builder->add('paymentNote', TextareaType::class, array(
            'label' => 'Payment note',
            'required' => false,
            'mapped' => false,
            'attr' => array(
                'rows' => 2
            )
        ));

    }

    /**
     * @param OptionsResolver $resolver
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Entity\Membership',
            'em' => null,
        ));
    }

    /**
     * Required function for form types
     * @return string
     */
    public function getName()
    {
        return "membership_type";
    }
}