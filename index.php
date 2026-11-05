<?php

// Fallback for hosts without mod_rewrite: send visitors to the public folder.
header('Location: public/', true, 302);
