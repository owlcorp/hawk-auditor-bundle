<?php

namespace Doctrine\Common {
    class EventArgs
    {
    }
}

namespace Doctrine\Persistence\Event {
    class LifecycleEventArgs extends \Doctrine\Common\EventArgs
    {
    }
}

namespace Doctrine\ORM\Event {
    //Here to simulate existence of the newer class on Doctrine <2.14
    class PostLoadEventArgs extends \Doctrine\Persistence\Event\LifecycleEventArgs
    {
    }
}
