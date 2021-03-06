<?php

namespace AppBundle\Controller\Payment;

use AppBundle\Entity\Payment;
use AppBundle\Form\Type\AddCreditType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AddCreditController extends Controller
{
    /**
     * @param Request $request
     * @return Response
     * @Route("member/add-credit", name="add_credit")
     */
    public function addCredit(Request $request)
    {
        /** @var \AppBundle\Entity\Contact $user */
        $user = $this->getUser();

        $em = $this->getDoctrine()->getManager();

        /** @var \AppBundle\Repository\ContactRepository $contactRepo */
        $contactRepo = $em->getRepository('AppBundle:Contact');

        /** @var \AppBundle\Services\Contact\ContactService $contactService */
        $contactService = $this->get('service.contact');

        /** @var \AppBundle\Services\Payment\PaymentService $paymentService */
        $paymentService = $this->get('service.payment');

        /** @var \AppBundle\Repository\PaymentMethodRepository $pmRepo */
        $pmRepo = $em->getRepository('AppBundle:PaymentMethod');

        $minimumPaymentAmount = (float)$this->get('settings')->getSettingValue('stripe_minimum_payment');

        /** @var \AppBundle\Entity\Contact $contact */
        if ($contactId = $request->get('c')) {
            if (!$contact = $contactRepo->find($contactId)) {
                $this->addFlash('error', "Can't find that contact");
                return $this->redirectToRoute('add_credit');
            }
        } else {
            $contact = $this->getUser();
        }

        // Create the form
        $form = $this->createForm(AddCreditType::class, null, [
            'em' => $em,
            'action' => $this->generateUrl('add_credit')
        ]);

        $form->handleRequest($request);

        $paymentAmount = null;
        if ($request->get('amount')) {
            $paymentAmount = (float)$request->get('amount');
        }
        if ($paymentAmount == 0 && $minimumPaymentAmount > 0) {
            $paymentAmount = $minimumPaymentAmount;
        }
        if ($paymentAmount > 0 && !$form->isSubmitted()) {
            // Preset the value
            $form->get('paymentAmount')->setData(number_format($paymentAmount, 2, '.', ''));
        }

        if ($form->isSubmitted() && $form->isValid()) {

            $amount = $form->get('paymentAmount')->getData();

            if ($amount < 0) {
                $this->addFlash('error', "Payment amount must be more than zero. Refunds should be issued using the payments list.");
            } else if ($amount > 0) {

                if (!$paymentMethodId = $form->get('paymentMethod')->getData()) {
                    $this->addFlash("error", "No payment method chosen.");
                    return $this->redirectToRoute('payments');
                }

                /** @var $paymentMethod \AppBundle\Entity\PaymentMethod */
                if (!$paymentMethod = $pmRepo->find($paymentMethodId)) {
                    $this->addFlash("error", "No payment method found with ID {$paymentMethodId}.");
                    return $this->redirectToRoute('payments');
                }

                $paymentDate = new \DateTime();

                if ($paymentId = $request->get('paymentId')) {
                    // We've created a payment via Stripe payment intent, link it to the credit
                    $payments = $paymentService->get(['id' => $paymentId]);
                    $payment = $payments[0];
                } else {
                    // No existing payment exists
                    $payment = new Payment();
                }

                $payment->setCreatedBy($user);
                $payment->setContact($contact);
                $payment->setAmount($amount);
                $payment->setPaymentDate($paymentDate);
                $payment->setPaymentMethod($paymentMethod);
                if ($note = $form->get('paymentNote')->getData()) {
                    $payment->setNote($note);
                } else {
                    $payment->setNote("Credit added.");
                }

                if ($paymentService->create($payment)) {

                    $this->get('session')->set('pendingPaymentType', null);

                    $contactService->recalculateBalance($contact);
                    if ($request->get('return') == 'basket') {
                        $this->addFlash('success', 'Payment recorded OK. You can now complete your loan or reservation.');
                    } else {
                        $this->addFlash('success', 'Payment recorded OK.');
                    }
                } else {
                    $this->addFlash('error', 'There was an error creating the payment.');
                    foreach ($paymentService->errors AS $error) {
                        $this->addFlash('error', $error);
                    }
                }

                if ($request->get('return') == 'basket') {
                    return $this->redirectToRoute('basket_show', ['payment' => 'ok']);
                } else if ($request->get('return') == 'admin') {
                    return $this->redirectToRoute('contact', ['id' => $contact->getId()]);
                }

                return $this->redirectToRoute('payments');

            }

        }

        $contact = $contactService->loadCustomerCards($contact);

        // Switch the web session to requested user
        if ($user->getId() != $contact->getId()) {
            $this->get('session')->set('sessionUserId', $contact->getId());
        }

        if ($request->get('modal')) {
            // Opening from admin
            $template = 'modals/add_credit.html.twig';
        } else {
            // Member site
            $template = 'member_site/pages/add_credit.html.twig';
        }

        return $this->render(
            $template,
            [
                'user'    => $contact,
                'contact' => $contact,
                'initialPaymentAmount' => $paymentAmount,
                'form' => $form->createView()
            ]
        );

    }

}
