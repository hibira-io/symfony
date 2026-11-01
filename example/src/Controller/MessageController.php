<?php

namespace App\Controller;

use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MessageController extends AbstractController
{
    #[Route('/', name: 'messages', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        return $this->render('message/index.html.twig', [
            'messages' => $em->getRepository(Message::class)->findBy([], ['id' => 'ASC'], 50),
        ]);
    }

    #[Route('/messages', name: 'message_create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $message = new Message();
        $message->author = mb_substr(trim((string) $request->request->get('author')) ?: 'Guest', 0, 40);
        $message->body = mb_substr(trim((string) $request->request->get('body')), 0, 280);

        if ($message->body !== '') {
            $em->persist($message);
            $em->flush(); // #[Broadcast] sends the new row to every open page
        }

        return $this->redirectToRoute('messages', ['author' => $message->author], Response::HTTP_SEE_OTHER);
    }

    #[Route('/messages/{id}/delete', name: 'message_delete', methods: ['POST'])]
    public function delete(Message $message, EntityManagerInterface $em): Response
    {
        $em->remove($message);
        $em->flush();

        return $this->redirectToRoute('messages', [], Response::HTTP_SEE_OTHER);
    }
}
